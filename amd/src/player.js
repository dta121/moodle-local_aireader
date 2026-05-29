// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Player AMD module for local_aireader.
 *
 * Mounts the player template into the hook-injected #local-aireader-mount,
 * polls local_aireader_get_status, drives playback, and wires podcast-style
 * controls: skip ±15s, click+drag scrub, speed (persisted in localStorage),
 * language switcher, manager toggle, MediaSession API for lock-screen
 * controls, and keyboard shortcuts (space/k = play, ←/j = back 15,
 * →/l = forward 15).
 *
 * @module     local_aireader/player
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

const STATE = {
    LOADING: 'loading',
    PENDING: 'pending',
    GENERATING: 'generating',
    READY: 'ready',
    ERROR: 'error',
    STALE: 'stale',
};

const SPEED_STORAGE_KEY = 'local_aireader_playbackrate';
const TRANSCRIPT_OPEN_KEY = 'local_aireader_transcriptopen';
const SKIP_SECONDS = 15;
const POSITION_WRITE_INTERVAL_MS = 5000;
const RESUME_MIN_SECONDS = 5;
const RESUME_TAIL_BUFFER = 5;
const HANDS_OFF_WINDOW_MS = 10000;
const IN_PLACE_MATCH_THRESHOLD = 0.5;

const formatTime = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '--:--';
    }
    const s = Math.floor(seconds);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r.toString().padStart(2, '0')}`;
};

const formatEstimateMinutes = (seconds) => {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return null;
    }
    const m = Math.max(1, Math.round(seconds / 60));
    return `${m} min`;
};

const str = (config, key, fallback) => {
    if (config && config.strings && typeof config.strings[key] === 'string') {
        return config.strings[key];
    }
    return fallback || '';
};

const setOverride = (config, enabled) => Ajax.call([{
    methodname: 'local_aireader_set_override',
    args: {
        cmid: config.cmid,
        module: config.module,
        chapterid: config.module === 'book' ? (config.chapterid || 0) : 0,
        enabled,
    },
}])[0];

const loadSavedSpeed = () => {
    try {
        const raw = window.localStorage.getItem(SPEED_STORAGE_KEY);
        if (!raw) {
            return 1;
        }
        const parsed = parseFloat(raw);
        if (parsed >= 0.5 && parsed <= 3) {
            return parsed;
        }
    } catch (e) {
        // LocalStorage may be blocked in privacy modes; fall through.
    }
    return 1;
};

const saveSpeed = (rate) => {
    try {
        window.localStorage.setItem(SPEED_STORAGE_KEY, String(rate));
    } catch (e) {
        // LocalStorage may be blocked; the missing preference is acceptable.
    }
};

class Player {
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.audio = root.querySelector('[data-region="audio"]');
        this.statusEl = root.querySelector('[data-region="status"]');
        this.timeEl = root.querySelector('[data-region="time"]');
        this.playBtn = root.querySelector('.local-aireader-playpause');
        this.restartBtn = root.querySelector('.local-aireader-restart');
        this.regenBtn = root.querySelector('.local-aireader-regen');
        this.skipBackBtn = root.querySelector('.local-aireader-skipback');
        this.skipFwdBtn = root.querySelector('.local-aireader-skipfwd');
        this.iconPlay = root.querySelector('.local-aireader-icon-play');
        this.iconPause = root.querySelector('.local-aireader-icon-pause');
        this.progressEl = root.querySelector('[data-region="progress"]');
        this.progressFill = root.querySelector('[data-region="progress-fill"]');
        this.progressThumb = root.querySelector('[data-region="progress-thumb"]');
        this.speedSelect = root.querySelector('[data-action="set-speed"]');
        this.managerBox = root.querySelector('[data-region="manager"]');
        this.managerBtn = this.managerBox && this.managerBox.querySelector('[data-action="toggle-enabled"]');
        this.langPicker = root.querySelector('[data-region="langpicker"]');
        this.langSelect = this.langPicker && this.langPicker.querySelector('[data-action="set-lang"]');

        this.polling = false;
        this.estimateSecs = 0;
        this.dragging = false;
        this.assetId = 0;
        this.pendingResumePosition = 0;
        this.appliedResume = false;
        this.lastPositionWriteAt = 0;

        // Karaoke / transcript state.
        this.segments = [];
        this.transcriptFetched = false;
        this.useInPlace = false;
        this.inPlaceMarks = [];
        this.paneSegments = [];
        this.activeSegIdx = -1;
        this.lastUserScrollAt = 0;
        this.transcriptToggleBtn = root.querySelector('[data-action="toggle-transcript"]');
        this.transcriptPane = root.querySelector('[data-region="transcript-pane"]');
        this.transcriptList = root.querySelector('[data-region="transcript-list"]');
        this.transcriptEmpty = root.querySelector('[data-region="transcript-empty"]');
        this.transcriptToggleLabel = root.querySelector('[data-region="transcript-toggle-label"]');

        if (config.canmanage) {
            this.regenBtn.classList.remove('d-none');
            this.managerBox.classList.remove('d-none');
        }
        this.populateLanguages();
        this.applySavedSpeed();

        this.bindEvents();
        this.bindKeyboard();
        this.setupMediaSession();

        this.setStatus(STATE.LOADING, str(this.config, 'loadingaudio', 'Loading audio…'));
        this.refresh();
    }

    populateLanguages() {
        const langs = Array.isArray(this.config.languages) ? this.config.languages : [];
        if (!this.langPicker || !this.langSelect || langs.length < 2) {
            return;
        }
        this.langSelect.innerHTML = '';
        langs.forEach((lang) => {
            const opt = document.createElement('option');
            opt.value = lang.code;
            opt.textContent = lang.name || lang.code;
            if (lang.code === this.config.lang) {
                opt.selected = true;
            }
            this.langSelect.appendChild(opt);
        });
        this.langPicker.classList.remove('d-none');
    }

    applySavedSpeed() {
        const rate = loadSavedSpeed();
        this.audio.playbackRate = rate;
        if (this.speedSelect) {
            const opt = Array.from(this.speedSelect.options).find((o) => parseFloat(o.value) === rate);
            if (opt) {
                this.speedSelect.value = opt.value;
            }
        }
    }

    bindEvents() {
        this.playBtn.addEventListener('click', () => this.togglePlay());
        this.restartBtn.addEventListener('click', () => this.restart());
        this.regenBtn.addEventListener('click', () => this.regen());
        this.skipBackBtn.addEventListener('click', () => this.skip(-SKIP_SECONDS));
        this.skipFwdBtn.addEventListener('click', () => this.skip(SKIP_SECONDS));

        if (this.speedSelect) {
            this.speedSelect.addEventListener('change', () => {
                const rate = parseFloat(this.speedSelect.value) || 1;
                this.audio.playbackRate = rate;
                saveSpeed(rate);
            });
        }

        this.audio.addEventListener('play', () => this.renderPlaying(true));
        this.audio.addEventListener('pause', () => {
            this.renderPlaying(false);
            // Don't write on pause-due-to-ended; the ended handler resets to 0.
            if (!this.audio.ended) {
                this.flushPositionWrite();
            }
        });
        this.audio.addEventListener('ended', () => {
            this.renderPlaying(false);
            this.writePosition(0, true);
        });
        this.audio.addEventListener('timeupdate', () => {
            this.renderTime();
            this.maybeWritePosition();
            this.updateActiveSegment();
        });
        this.audio.addEventListener('loadedmetadata', () => {
            this.renderTime();
            this.applyResumeIfNeeded();
        });
        this.audio.addEventListener('durationchange', () => this.renderTime());
        this.audio.addEventListener('error', () => {
            this.setStatus(STATE.ERROR, str(this.config, 'playbackfailed', 'Audio playback failed.'));
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                this.flushPositionWrite();
            }
        });

        if (this.managerBtn) {
            this.managerBtn.addEventListener('click', () => this.disableHere());
        }
        if (this.langSelect) {
            this.langSelect.addEventListener('change', () => this.changeLanguage(this.langSelect.value));
        }

        if (this.transcriptToggleBtn) {
            this.transcriptToggleBtn.addEventListener('click', () => this.toggleTranscript());
        }

        // Track human-initiated scrolls so we suspend auto-scroll briefly afterward.
        const onUserScroll = (e) => {
            if (e.isTrusted) {
                this.lastUserScrollAt = Date.now();
            }
        };
        window.addEventListener('wheel', onUserScroll, {passive: true});
        window.addEventListener('touchmove', onUserScroll, {passive: true});

        this.bindProgressEvents();
    }

    bindProgressEvents() {
        if (!this.progressEl) {
            return;
        }
        const startDrag = (clientX) => {
            this.dragging = true;
            this.seekFromClientX(clientX);
        };
        const moveDrag = (clientX) => {
            if (this.dragging) {
                this.seekFromClientX(clientX);
            }
        };
        const endDrag = () => {
            this.dragging = false;
        };

        this.progressEl.addEventListener('mousedown', (e) => {
            if (e.button !== 0) {
                return;
            }
            startDrag(e.clientX);
            e.preventDefault();
        });
        document.addEventListener('mousemove', (e) => moveDrag(e.clientX));
        document.addEventListener('mouseup', endDrag);

        this.progressEl.addEventListener('touchstart', (e) => {
            if (e.touches.length) {
                startDrag(e.touches[0].clientX);
            }
        }, {passive: true});
        this.progressEl.addEventListener('touchmove', (e) => {
            if (e.touches.length) {
                moveDrag(e.touches[0].clientX);
            }
        }, {passive: true});
        this.progressEl.addEventListener('touchend', endDrag);
        this.progressEl.addEventListener('touchcancel', endDrag);

        // Keyboard on the scrubber itself: left/right = ±5s for fine seek,
        // Home/End = jump to start/end.
        this.progressEl.addEventListener('keydown', (e) => {
            switch (e.code) {
                case 'Home':
                    e.preventDefault();
                    this.audio.currentTime = 0;
                    break;
                case 'End':
                    if (Number.isFinite(this.audio.duration)) {
                        e.preventDefault();
                        this.audio.currentTime = this.audio.duration;
                    }
                    break;
                default:
                    break;
            }
        });
    }

    seekFromClientX(clientX) {
        if (!this.audio.src || !Number.isFinite(this.audio.duration) || this.audio.duration <= 0) {
            return;
        }
        const rect = this.progressEl.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        this.audio.currentTime = ratio * this.audio.duration;
    }

    skip(deltaSecs) {
        if (!this.audio.src) {
            return;
        }
        const dur = Number.isFinite(this.audio.duration) ? this.audio.duration : 0;
        const target = Math.max(0, Math.min(dur || this.audio.currentTime + deltaSecs, this.audio.currentTime + deltaSecs));
        this.audio.currentTime = target;
    }

    bindKeyboard() {
        // Player-scoped: only fires when the player (or a descendant) has focus.
        // We deliberately don't bind document-level handlers so we don't hijack
        // keys for the rest of the Moodle page.
        this.root.addEventListener('keydown', (e) => this.handleKey(e));
    }

    handleKey(e) {
        if (e.target.matches('input, textarea, select')) {
            return;
        }
        let handled = false;
        switch (e.code) {
            case 'KeyK':
                this.togglePlay();
                handled = true;
                break;
            case 'KeyJ':
            case 'ArrowLeft':
                this.skip(-SKIP_SECONDS);
                handled = true;
                break;
            case 'KeyL':
            case 'ArrowRight':
                this.skip(SKIP_SECONDS);
                handled = true;
                break;
            default:
                break;
        }
        // Space is intentionally not handled here — focused buttons handle it
        // natively and we don't want to double-fire.
        if (handled) {
            e.preventDefault();
        }
    }

    setupMediaSession() {
        if (!('mediaSession' in navigator)) {
            return;
        }
        const setHandler = (action, handler) => {
            try {
                navigator.mediaSession.setActionHandler(action, handler);
            } catch (e) {
                // Browser may not support every action; that's fine.
            }
        };
        setHandler('play', () => {
            this.audio.play().catch(() => {
                // Autoplay policies may block; nothing actionable here.
            });
        });
        setHandler('pause', () => this.audio.pause());
        setHandler('seekbackward', (details) => this.skip(-((details && details.seekOffset) || SKIP_SECONDS)));
        setHandler('seekforward', (details) => this.skip((details && details.seekOffset) || SKIP_SECONDS));
        setHandler('seekto', (details) => {
            if (details && Number.isFinite(details.seekTime)) {
                this.audio.currentTime = details.seekTime;
            }
        });
    }

    updateMediaSessionMetadata() {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function') {
            return;
        }
        try {
            navigator.mediaSession.metadata = new window.MediaMetadata({
                title: document.title || str(this.config, 'listentitle', 'Listen to this content'),
                artist: str(this.config, 'listentitle', 'AI narration'),
            });
        } catch (e) {
            // MediaMetadata is best-effort; ignore unsupported browsers.
        }
    }

    callStatus() {
        return Ajax.call([{
            methodname: 'local_aireader_get_status',
            args: {
                cmid: this.config.cmid,
                module: this.config.module,
                chapterid: this.config.chapterid || 0,
                lang: this.config.lang || 'en',
            },
        }])[0];
    }

    callRegen() {
        return Ajax.call([{
            methodname: 'local_aireader_request_regen',
            args: {
                cmid: this.config.cmid,
                module: this.config.module,
                chapterid: this.config.chapterid || 0,
                lang: this.config.lang || 'en',
            },
        }])[0];
    }

    async refresh() {
        try {
            const result = await this.callStatus();
            this.handleStatus(result);
        } catch (e) {
            this.setStatus(STATE.ERROR, e.message || str(this.config, 'couldnotload', 'Could not load audio.'));
        }
    }

    handleStatus(result) {
        if (result.durationsecs && result.durationsecs > 0) {
            this.estimateSecs = result.durationsecs;
            this.renderTime();
        }

        if (result.assetid) {
            this.assetId = Number(result.assetid) || 0;
        }
        const resumePos = Math.floor(Number(result.resumeposition) || 0);
        this.pendingResumePosition = resumePos >= RESUME_MIN_SECONDS ? resumePos : 0;
        this.appliedResume = false;

        if (result.status === 'ready' && result.audiourl) {
            this.audio.src = result.audiourl;
            this.playBtn.disabled = false;
            this.restartBtn.disabled = false;
            this.skipBackBtn.disabled = false;
            this.skipFwdBtn.disabled = false;
            this.setStatus(STATE.READY, str(this.config, 'ready', 'Ready to play.'));
            this.updateMediaSessionMetadata();
            this.polling = false;
            this.fetchTranscriptIfNeeded();
            return;
        }

        if (result.status === 'error') {
            this.setStatus(STATE.ERROR, result.message || str(this.config, 'generationfailed', 'Audio generation failed.'));
            this.polling = false;
            return;
        }

        // Pending, generating, or stale — keep polling.
        this.setStatus(result.status, result.message || str(this.config, 'beingprepared', 'Audio is being prepared…'));
        this.schedulePoll();
    }

    schedulePoll() {
        if (this.polling) {
            return;
        }
        this.polling = true;
        const ms = Math.max(2, Number(this.config.pollinterval) || 5) * 1000;
        window.setTimeout(() => {
            this.polling = false;
            this.refresh();
        }, ms);
    }

    setStatus(state, message) {
        this.root.dataset.state = state;
        this.statusEl.textContent = message;
        const busy = state === STATE.LOADING || state === STATE.PENDING || state === STATE.GENERATING || state === STATE.STALE;
        this.statusEl.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    togglePlay() {
        if (!this.audio.src) {
            return;
        }
        if (this.audio.paused) {
            this.audio.play().catch((e) => {
                this.setStatus(STATE.ERROR, e.message || str(this.config, 'playbackblocked', 'Playback blocked.'));
            });
        } else {
            this.audio.pause();
        }
    }

    restart() {
        if (!this.audio.src) {
            return;
        }
        this.audio.currentTime = 0;
        // Restarting consumes the saved resume position so a subsequent reload
        // doesn't jump straight back to where they were.
        this.writePosition(0, true);
        this.audio.play().catch(() => { /* User gesture required. */ });
    }

    /**
     * Apply the server-supplied resume position the first time we know the
     * audio's true duration. Skipped if the saved position is in the last
     * RESUME_TAIL_BUFFER seconds (treat as completed → restart from 0).
     */
    applyResumeIfNeeded() {
        if (this.appliedResume || !this.pendingResumePosition) {
            return;
        }
        const dur = this.audio.duration;
        if (!Number.isFinite(dur) || dur <= 0) {
            return;
        }
        this.appliedResume = true;
        if (this.pendingResumePosition >= dur - RESUME_TAIL_BUFFER) {
            // Effectively completed last time; start fresh.
            this.pendingResumePosition = 0;
            return;
        }
        this.audio.currentTime = this.pendingResumePosition;
        this.renderTime();
        this.pendingResumePosition = 0;
    }

    /**
     * Throttled timeupdate-driven position write. At most one network call
     * per POSITION_WRITE_INTERVAL_MS, only while playing.
     */
    maybeWritePosition() {
        if (this.audio.paused || this.audio.ended) {
            return;
        }
        const now = Date.now();
        if (now - this.lastPositionWriteAt < POSITION_WRITE_INTERVAL_MS) {
            return;
        }
        this.lastPositionWriteAt = now;
        this.writePosition(Math.floor(this.audio.currentTime || 0));
    }

    /**
     * Force a position write right now (used on pause + visibility hidden).
     */
    flushPositionWrite() {
        if (!this.assetId || !this.audio.src) {
            return;
        }
        const pos = Math.floor(this.audio.currentTime || 0);
        if (pos < RESUME_MIN_SECONDS) {
            return;
        }
        this.lastPositionWriteAt = Date.now();
        this.writePosition(pos);
    }

    /**
     * Persist a specific position. If `force` is true, sends even when 0.
     *
     * @param {number} position
     * @param {boolean} [force]
     */
    writePosition(position, force) {
        if (!this.assetId) {
            return;
        }
        if (!force && position < RESUME_MIN_SECONDS) {
            return;
        }
        try {
            Ajax.call([{
                methodname: 'local_aireader_set_position',
                args: {
                    assetid: this.assetId,
                    position: Math.max(0, Math.floor(position)),
                },
            }])[0].catch(() => {
                // Position writes are best-effort; ignore transport errors.
            });
        } catch (e) {
            // Ignore.
        }
    }

    async regen() {
        try {
            this.setStatus(STATE.PENDING, str(this.config, 'queuedforregen', 'Queued for regeneration…'));
            this.playBtn.disabled = true;
            this.restartBtn.disabled = true;
            this.skipBackBtn.disabled = true;
            this.skipFwdBtn.disabled = true;
            await this.callRegen();
            this.schedulePoll();
        } catch (e) {
            Notification.exception(e);
        }
    }

    async disableHere() {
        try {
            await setOverride(this.config, false);
            renderOffline(this.root.parentNode, {...this.config, enabled: false});
        } catch (e) {
            Notification.exception(e);
        }
    }

    changeLanguage(newlang) {
        if (!newlang || newlang === this.config.lang) {
            return;
        }
        this.config.lang = newlang;
        this.audio.pause();
        this.audio.removeAttribute('src');
        this.playBtn.disabled = true;
        this.restartBtn.disabled = true;
        this.skipBackBtn.disabled = true;
        this.skipFwdBtn.disabled = true;
        this.estimateSecs = 0;
        this.assetId = 0;
        this.pendingResumePosition = 0;
        this.appliedResume = false;
        this.lastPositionWriteAt = 0;
        // Tear down any prior transcript so the new language's transcript loads cleanly.
        this.transcriptFetched = false;
        this.segments = [];
        this.activeSegIdx = -1;
        this.inPlaceMarks.forEach(unwrapMark);
        this.inPlaceMarks = [];
        this.useInPlace = false;
        if (this.transcriptList) {
            this.transcriptList.innerHTML = '';
        }
        if (this.transcriptEmpty) {
            this.transcriptEmpty.classList.remove('d-none');
            this.transcriptEmpty.textContent = str(this.config, 'preparingtranscript', 'Preparing transcript…');
        }
        if (this.transcriptToggleBtn) {
            this.transcriptToggleBtn.classList.add('d-none');
        }
        this.renderTime();
        this.setStatus(STATE.LOADING, str(this.config, 'preparinglang', 'Preparing in selected language…'));
        this.polling = false;
        this.refresh();
    }

    renderPlaying(isPlaying) {
        this.iconPlay.classList.toggle('d-none', isPlaying);
        this.iconPause.classList.toggle('d-none', !isPlaying);
        this.playBtn.setAttribute(
            'aria-label',
            isPlaying ? str(this.config, 'pause', 'Pause') : str(this.config, 'play', 'Play')
        );
    }

    // -- Transcript + karaoke --

    async fetchTranscriptIfNeeded() {
        if (this.transcriptFetched || !this.assetId) {
            return;
        }
        this.transcriptFetched = true;
        try {
            const result = await Ajax.call([{
                methodname: 'local_aireader_get_transcript',
                args: {assetid: this.assetId},
            }])[0];
            const segs = Array.isArray(result.segments) ? result.segments : [];
            if (!result.aligned || !segs.length) {
                // No alignment yet (or not enabled). Toggle stays hidden.
                return;
            }
            this.segments = segs.map((s) => ({
                idx: Number(s.idx) || 0,
                startms: Number(s.startms) || 0,
                endms: Number(s.endms) || 0,
                text: String(s.text || ''),
            }));
            this.useInPlace = false;
            this.inPlaceMarks = [];

            if (this.config.highlightinplace) {
                const placed = tryInPlaceWrap(this.segments, this.config.module);
                const ratio = this.segments.length ? placed.matchedCount / this.segments.length : 0;
                // Keep every successful mark regardless of ratio: partial
                // in-page highlighting is still useful, and the transcript
                // pane is rendered alongside as a complementary view for
                // segments that couldn't be wrapped. useInPlace is now just
                // a hint for whether auto-scroll should prefer the in-page
                // marks (when most segments matched) or the pane buttons.
                this.useInPlace = ratio >= IN_PLACE_MATCH_THRESHOLD;
                this.inPlaceMarks = placed.marks;
                placed.marks.forEach((m) => {
                    if (!m) {
                        return;
                    }
                    m.addEventListener('click', () => {
                        const idx = parseInt(m.dataset.segmentIdx, 10);
                        this.seekToSegment(idx);
                    });
                });
            }
            this.renderTranscriptPane();
            this.revealTranscriptToggle();
            this.restoreTranscriptOpen();
        } catch (e) {
            // Transcript is best-effort; ignore network errors.
            this.transcriptFetched = false;
        }
    }

    revealTranscriptToggle() {
        if (this.transcriptToggleBtn) {
            this.transcriptToggleBtn.classList.remove('d-none');
        }
    }

    restoreTranscriptOpen() {
        let open = false;
        try {
            open = window.localStorage.getItem(TRANSCRIPT_OPEN_KEY) === '1';
        } catch (e) {
            // LocalStorage may be blocked; default closed.
        }
        if (open) {
            this.setTranscriptOpen(true);
        }
    }

    toggleTranscript() {
        const isOpen = !this.transcriptPane.classList.contains('d-none');
        this.setTranscriptOpen(!isOpen);
        try {
            window.localStorage.setItem(TRANSCRIPT_OPEN_KEY, isOpen ? '0' : '1');
        } catch (e) {
            // Ignore.
        }
    }

    setTranscriptOpen(open) {
        if (!this.transcriptPane) {
            return;
        }
        this.transcriptPane.classList.toggle('d-none', !open);
        this.transcriptToggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !this.useInPlace && this.segments.length) {
            // Pane was just opened; make sure the current segment is in view.
            this.updateActiveSegment(true);
        }
    }

    renderTranscriptPane() {
        if (!this.transcriptList) {
            return;
        }
        this.transcriptList.innerHTML = '';
        this.paneSegments = [];
        if (!this.segments.length) {
            this.transcriptEmpty.classList.remove('d-none');
            return;
        }
        this.transcriptEmpty.classList.add('d-none');
        this.segments.forEach((seg) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'local-aireader-transcript-segment';
            btn.dataset.segmentIdx = String(seg.idx);
            btn.textContent = seg.text;
            btn.addEventListener('click', () => this.seekToSegment(seg.idx));
            li.appendChild(btn);
            this.transcriptList.appendChild(li);
            this.paneSegments.push(btn);
        });
    }

    seekToSegment(idx) {
        const seg = this.segments.find((s) => s.idx === idx);
        if (!seg || !this.audio.src) {
            return;
        }
        this.audio.currentTime = Math.max(0, seg.startms / 1000);
        this.audio.play().catch(() => { /* User gesture required. */ });
    }

    updateActiveSegment(force) {
        if (!this.segments.length) {
            return;
        }
        const ms = (this.audio.currentTime || 0) * 1000;
        const idx = findActiveSegmentIdx(this.segments, ms);
        if (idx === this.activeSegIdx && !force) {
            return;
        }
        this.activeSegIdx = idx;

        // Toggle in-place marks whenever any exist — even partial coverage
        // should light up as the audio plays.
        let activeMark = null;
        this.inPlaceMarks.forEach((m, i) => {
            if (!m) {
                return;
            }
            const isCurrent = this.segments[i] && this.segments[i].idx === idx;
            m.classList.toggle('is-current', isCurrent);
            if (isCurrent) {
                activeMark = m;
            }
        });

        // Pane spans.
        let activeBtn = null;
        this.paneSegments.forEach((btn) => {
            const isCurrent = parseInt(btn.dataset.segmentIdx, 10) === idx;
            btn.classList.toggle('is-current', isCurrent);
            if (isCurrent) {
                activeBtn = btn;
            }
        });

        // Auto-scroll the current segment into view, but only if the user
        // hasn't manually scrolled in the last HANDS_OFF_WINDOW_MS. Prefer
        // the in-page mark if it exists for this segment; otherwise fall
        // back to the pane button (when the pane is open).
        if (Date.now() - this.lastUserScrollAt < HANDS_OFF_WINDOW_MS) {
            return;
        }
        if (activeMark) {
            activeMark.scrollIntoView({behavior: 'smooth', block: 'center'});
        } else if (activeBtn && this.transcriptPane && !this.transcriptPane.classList.contains('d-none')) {
            activeBtn.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
    }

    renderTime() {
        const dur = this.audio.duration;
        const cur = this.audio.currentTime;
        const haveDur = Number.isFinite(dur) && dur > 0;

        if (haveDur) {
            this.timeEl.textContent = `${formatTime(cur)} / ${formatTime(dur)}`;
        } else if (this.estimateSecs > 0) {
            this.timeEl.textContent = `~${formatEstimateMinutes(this.estimateSecs)} listen`;
        } else {
            this.timeEl.textContent = '--:--';
        }

        if (this.progressEl) {
            const ratio = haveDur && dur > 0 ? Math.max(0, Math.min(1, cur / dur)) : 0;
            const pct = (ratio * 100).toFixed(2);
            this.progressFill.style.width = `${pct}%`;
            if (this.progressThumb) {
                this.progressThumb.style.left = `${pct}%`;
            }
            this.progressEl.setAttribute('aria-valuenow', Math.round(ratio * 100));
            if (haveDur) {
                this.progressEl.setAttribute('aria-valuetext', `${formatTime(cur)} of ${formatTime(dur)}`);
            }
        }
    }
}

const findInsertionTarget = (module) => {
    const candidates = [
        module === 'book'
            ? document.querySelector('.book_content')
            : null,
        document.querySelector('[role="main"] .activity-description'),
        document.querySelector('[role="main"]'),
        document.getElementById('region-main'),
        document.querySelector('#page-content'),
    ];
    return candidates.find((el) => el) || document.body;
};

/**
 * Binary-search the active segment index for a given timestamp.
 *
 * @param {Array} segments Sorted by startms ascending.
 * @param {number} ms Current playback time in milliseconds.
 * @returns {number} segments[i].idx of the active segment, or -1 if none.
 */
const findActiveSegmentIdx = (segments, ms) => {
    let lo = 0;
    let hi = segments.length - 1;
    let result = -1;
    while (lo <= hi) {
        const mid = Math.floor((lo + hi) / 2);
        if (segments[mid].startms <= ms) {
            result = mid;
            lo = mid + 1;
        } else {
            hi = mid - 1;
        }
    }
    if (result === -1) {
        return -1;
    }
    if (ms > segments[result].endms + 250) {
        // 250ms grace; otherwise treat as a gap between segments.
        return -1;
    }
    return segments[result].idx;
};

/**
 * Unwrap a previously-placed <mark>, leaving its text content in place.
 *
 * @param {HTMLElement} mark
 */
const unwrapMark = (mark) => {
    if (!mark || !mark.parentNode) {
        return;
    }
    while (mark.firstChild) {
        mark.parentNode.insertBefore(mark.firstChild, mark);
    }
    mark.parentNode.removeChild(mark);
};

/**
 * Locate the activity body and find every segment's text inside it. Returns
 * an array of <mark> elements (or nulls for misses) in segment order plus a
 * count of how many were successfully matched.
 *
 * Strategy: build a normalized flat string from all text nodes (whitespace
 * collapsed, punctuation dropped), search for each segment's normalized text,
 * and use the Range API to wrap the match in a <mark>. If the range crosses
 * an element boundary, surroundContents throws and we count the segment as
 * a miss.
 *
 * Container selection is intentionally narrow. We deliberately do NOT fall
 * back to [role="main"] because that includes breadcrumbs, secondary
 * navigation, the page header, and other Moodle chrome which we must never
 * mutate. Misses are preferable to wrapping chrome text.
 *
 * @param {Array} segments
 * @param {string} module 'page' or 'book'.
 * @returns {{marks: Array<HTMLElement|null>, matchedCount: number}}
 */
const tryInPlaceWrap = (segments, module) => {
    const container = findWrapContainer(module);
    if (!container) {
        return {marks: [], matchedCount: 0};
    }

    const marks = new Array(segments.length).fill(null);
    let matched = 0;

    segments.forEach((seg, i) => {
        const m = wrapSegmentInContainer(container, seg);
        marks[i] = m;
        if (m) {
            matched++;
        }
    });

    return {marks, matchedCount: matched};
};

/**
 * The set of ancestor selectors a text node may NOT live under to be eligible
 * for in-place wrapping. Anything in the breadcrumb, nav, header, footer,
 * buttons, our own player mount, or script/style tags is off limits — we
 * mustn't visually mark those even if the segment text matches by chance.
 *
 * Kept as a string so it goes through one closest() call per text node.
 */
const WRAP_REJECT_SELECTOR =
    'nav, header, footer, aside, button, ' +
    '[role="navigation"], [role="banner"], [role="contentinfo"], ' +
    '.breadcrumb, .navbar, .nav, ' +
    '.primary-navigation, .secondary-navigation, .secondary-tabs, ' +
    '.activity-header, .activity-information, .completion-info, ' +
    '[data-region="activity-information"], [data-region="completion-info"], ' +
    '[data-for="page-activity-header"], .badge, ' +
    '.local-aireader, script, style, noscript';

/**
 * Pick the narrowest plausible container inside the rendered activity body.
 * Returns null if we can't find any candidate.
 *
 * Only narrow body selectors — never #region-main or [role="main"], which
 * sweep in the activity-header / completion widgets whose JS we must not touch.
 *
 * @param {string} module 'page' or 'book'.
 * @returns {Element|null}
 */
const findWrapContainer = (module) => {
    if (module === 'book') {
        return document.querySelector('.book_content');
    }
    const candidates = [
        '[role="main"] .activity-description',
        '#region-main .box.generalbox',
        '#region-main article',
        '#region-main [data-region="activity-content"]',
    ];
    for (const sel of candidates) {
        const el = document.querySelector(sel);
        if (el) {
            return el;
        }
    }
    return null;
};

/**
 * Search for a segment's text in `container` and wrap the matching range in
 * a <mark>. Returns the new <mark> or null on miss.
 *
 * @param {Element} container
 * @param {{idx:number, text:string}} seg
 * @returns {HTMLElement|null}
 */
const wrapSegmentInContainer = (container, seg) => {
    const needle = normalizeForMatch(seg.text);
    if (!needle) {
        return null;
    }

    // Build a flat string + position map across text nodes, rejecting any
    // text that lives under chrome we must not mutate (nav, breadcrumbs,
    // buttons, our own player, etc).
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => {
            if (!n.parentNode || !n.parentNode.closest) {
                return NodeFilter.FILTER_REJECT;
            }
            if (n.parentNode.closest(WRAP_REJECT_SELECTOR)) {
                return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });

    const nodes = [];
    let raw = '';
    let node;
    while ((node = walker.nextNode())) {
        nodes.push({node, start: raw.length});
        raw += node.nodeValue;
    }
    if (!raw) {
        return null;
    }

    // Build normalized text + reverse map (normIdx -> rawIdx).
    let norm = '';
    const normToRaw = [];
    let prevSpace = true;
    for (let i = 0; i < raw.length; i++) {
        const ch = raw[i];
        if (/\s/.test(ch)) {
            if (!prevSpace) {
                norm += ' ';
                normToRaw.push(i);
                prevSpace = true;
            }
        } else {
            const lower = ch.toLowerCase();
            if (/[\p{L}\p{N}]/u.test(lower)) {
                norm += lower;
                normToRaw.push(i);
                prevSpace = false;
            }
            // Otherwise (punctuation), skip — Whisper transcripts often differ
            // on punctuation; matching letters/digits is more robust.
        }
    }
    if (norm.endsWith(' ')) {
        norm = norm.slice(0, -1);
        normToRaw.pop();
    }

    const startNorm = norm.indexOf(needle);
    if (startNorm === -1) {
        return null;
    }
    const endNorm = startNorm + needle.length - 1;
    if (endNorm >= normToRaw.length) {
        return null;
    }
    const rawStart = normToRaw[startNorm];
    const rawEnd = normToRaw[endNorm];

    // Map raw offsets back to (node, offset).
    const locate = (rawIdx) => {
        for (let i = 0; i < nodes.length; i++) {
            const entry = nodes[i];
            const end = entry.start + entry.node.nodeValue.length;
            if (rawIdx >= entry.start && rawIdx < end) {
                return {node: entry.node, offset: rawIdx - entry.start};
            }
        }
        return null;
    };
    const startPos = locate(rawStart);
    const endPos = locate(rawEnd);
    if (!startPos || !endPos) {
        return null;
    }

    const range = document.createRange();
    try {
        range.setStart(startPos.node, startPos.offset);
        range.setEnd(endPos.node, endPos.offset + 1);
    } catch (e) {
        return null;
    }

    const mark = document.createElement('mark');
    mark.className = 'local-aireader-mark';
    mark.dataset.segmentIdx = String(seg.idx);
    try {
        range.surroundContents(mark);
    } catch (e) {
        // Range crosses an element boundary; can't wrap cleanly. Caller will
        // count this as a miss and either accept partial coverage or fall back.
        return null;
    }
    return mark;
};

/**
 * Normalize text for fuzzy matching: lowercase, strip punctuation,
 * collapse whitespace.
 *
 * @param {string} s
 * @returns {string}
 */
const normalizeForMatch = (s) => {
    if (!s) {
        return '';
    }
    return String(s)
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\s]/gu, '')
        .replace(/\s+/g, ' ')
        .trim();
};

const renderOffline = async(mount, config) => {
    try {
        const {html, js} = await Templates.renderForPromise('local_aireader/manager_offline', {
            message: str(config, 'offheremsg', 'AI narration is turned off here.'),
            actionlabel: str(config, 'turnonhere', 'Turn on'),
            regionlabel: str(config, 'offlinedisabled', 'AI narration disabled'),
        });
        Templates.replaceNodeContents(mount, html, js);
        const btn = mount.querySelector('[data-action="enable"]');
        if (btn) {
            btn.addEventListener('click', async() => {
                btn.disabled = true;
                try {
                    await setOverride(config, true);
                    window.location.reload();
                } catch (e) {
                    btn.disabled = false;
                    Notification.exception(e);
                }
            });
        }
    } catch (e) {
        Notification.exception(e);
    }
};

const renderPlayer = async(mount, config) => {
    try {
        const {html, js} = await Templates.renderForPromise('local_aireader/player', {
            disclosure: config.disclosure || '',
            managerlabelon: str(config, 'turnoffhere', 'Turn off'),
            managerlabeloff: str(config, 'turnonhere', 'Turn on'),
            stringListen: str(config, 'listentitle', 'Listen to this content'),
            stringLoading: str(config, 'loading', 'Loading…'),
            stringPlay: str(config, 'play', 'Play'),
            stringSkipBack: str(config, 'skipback', 'Skip back 15 seconds'),
            stringSkipForward: str(config, 'skipforward', 'Skip forward 15 seconds'),
            stringLanguage: str(config, 'language', 'Language'),
            stringProgress: str(config, 'progress', 'Playback position'),
            stringSpeed: str(config, 'speed', 'Speed'),
            stringPlaybackSpeed: str(config, 'playbackspeed', 'Playback speed'),
            stringRestart: str(config, 'restart', 'Restart from beginning'),
            stringRegenerate: str(config, 'regenerate', 'Regenerate audio'),
            stringShowTranscript: str(config, 'showtranscript', 'Show transcript'),
            stringTranscript: str(config, 'transcriptlabel', 'Transcript'),
            stringPreparingTranscript: str(config, 'preparingtranscript', 'Preparing transcript…'),
        });
        Templates.replaceNodeContents(mount, html, js);
        const root = mount.querySelector('.local-aireader-player');
        if (root) {
            new Player(root, config);
        }
    } catch (e) {
        Notification.exception(e);
    }
};

export const init = async(config) => {
    const mount = document.getElementById('local-aireader-mount');
    if (!mount) {
        return;
    }

    const target = findInsertionTarget(config.module);
    if (target && target.parentNode && mount.parentNode !== target.parentNode) {
        target.parentNode.insertBefore(mount, target);
    }

    if (!config.enabled) {
        if (config.canmanage) {
            renderOffline(mount, config);
        }
        return;
    }
    renderPlayer(mount, config);
};
