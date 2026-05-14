// Player AMD module for local_aireader.
//
// Mounts the player template into the hook-injected #local-aireader-mount,
// polls local_aireader_get_status, drives playback, and wires podcast-style
// controls: skip ±15s, click+drag scrub, speed (persisted in localStorage),
// language switcher, manager toggle, MediaSession API for lock-screen
// controls, and keyboard shortcuts (space/k = play, ←/j = back 15,
// →/l = forward 15).

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
const SKIP_SECONDS = 15;

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

const scopeLabel = (config) => {
    if (config.module === 'book' && config.chapterid) {
        return 'chapter';
    }
    return config.module === 'book' ? 'book' : 'page';
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

        if (config.canmanage) {
            this.regenBtn.classList.remove('d-none');
            this.managerBox.classList.remove('d-none');
        }
        this.populateLanguages();
        this.applySavedSpeed();

        this.bindEvents();
        this.bindKeyboard();
        this.setupMediaSession();

        this.setStatus(STATE.LOADING, 'Loading audio…');
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
        this.audio.addEventListener('pause', () => this.renderPlaying(false));
        this.audio.addEventListener('ended', () => this.renderPlaying(false));
        this.audio.addEventListener('timeupdate', () => this.renderTime());
        this.audio.addEventListener('loadedmetadata', () => this.renderTime());
        this.audio.addEventListener('durationchange', () => this.renderTime());
        this.audio.addEventListener('error', () => {
            this.setStatus(STATE.ERROR, 'Audio playback failed.');
        });

        if (this.managerBtn) {
            this.managerBtn.addEventListener('click', () => this.disableHere());
        }
        if (this.langSelect) {
            this.langSelect.addEventListener('change', () => this.changeLanguage(this.langSelect.value));
        }

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
                title: document.title || 'Listen to this content',
                artist: 'AI narration',
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
            this.setStatus(STATE.ERROR, e.message || 'Could not load audio.');
        }
    }

    handleStatus(result) {
        if (result.durationsecs && result.durationsecs > 0) {
            this.estimateSecs = result.durationsecs;
            this.renderTime();
        }

        if (result.status === 'ready' && result.audiourl) {
            this.audio.src = result.audiourl;
            this.playBtn.disabled = false;
            this.restartBtn.disabled = false;
            this.skipBackBtn.disabled = false;
            this.skipFwdBtn.disabled = false;
            this.setStatus(STATE.READY, 'Ready to play.');
            this.updateMediaSessionMetadata();
            this.polling = false;
            return;
        }

        if (result.status === 'error') {
            this.setStatus(STATE.ERROR, result.message || 'Audio generation failed.');
            this.polling = false;
            return;
        }

        // Pending, generating, or stale — keep polling.
        this.setStatus(result.status, result.message || 'Audio is being prepared…');
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
                this.setStatus(STATE.ERROR, e.message || 'Playback blocked.');
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
        this.audio.play().catch(() => { /* User gesture required. */ });
    }

    async regen() {
        try {
            this.setStatus(STATE.PENDING, 'Queued for regeneration…');
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
        this.renderTime();
        this.setStatus(STATE.LOADING, 'Preparing in selected language…');
        this.polling = false;
        this.refresh();
    }

    renderPlaying(isPlaying) {
        this.iconPlay.classList.toggle('d-none', isPlaying);
        this.iconPause.classList.toggle('d-none', !isPlaying);
        this.playBtn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
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

const renderOffline = async(mount, config) => {
    try {
        const message = `AI narration is turned off for this ${scopeLabel(config)}.`;
        const actionLabel = `Turn on for this ${scopeLabel(config)}`;
        const {html, js} = await Templates.renderForPromise('local_aireader/manager_offline', {
            message,
            actionlabel: actionLabel,
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
            managerlabelon: `Turn off for this ${scopeLabel(config)}`,
            managerlabeloff: `Turn on for this ${scopeLabel(config)}`,
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
