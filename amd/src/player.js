// Player AMD module for local_aireader.
//
// The PHP hook injects an empty #local-aireader-mount div near the top of the
// body. This module:
//   1. Renders the player template into that mount.
//   2. Repositions the mount above the resource content region.
//   3. Polls local_aireader_get_status until ready or error.
//   4. Wires play/pause/restart/regen controls.

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

const formatTime = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '--:--';
    }
    const s = Math.floor(seconds);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r.toString().padStart(2, '0')}`;
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
        this.iconPlay = root.querySelector('.local-aireader-icon-play');
        this.iconPause = root.querySelector('.local-aireader-icon-pause');
        this.polling = false;

        if (config.canregenerate) {
            this.regenBtn.classList.remove('d-none');
        }

        this.bindEvents();
        this.setStatus(STATE.LOADING, 'Loading audio…');
        this.refresh();
    }

    bindEvents() {
        this.playBtn.addEventListener('click', () => this.togglePlay());
        this.restartBtn.addEventListener('click', () => this.restart());
        this.regenBtn.addEventListener('click', () => this.regen());

        this.audio.addEventListener('play', () => this.renderPlaying(true));
        this.audio.addEventListener('pause', () => this.renderPlaying(false));
        this.audio.addEventListener('ended', () => this.renderPlaying(false));
        this.audio.addEventListener('timeupdate', () => this.renderTime());
        this.audio.addEventListener('loadedmetadata', () => this.renderTime());
        this.audio.addEventListener('error', () => {
            this.setStatus(STATE.ERROR, 'Audio playback failed.');
        });
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
        if (result.status === 'ready' && result.audiourl) {
            this.audio.src = result.audiourl;
            this.playBtn.disabled = false;
            this.restartBtn.disabled = false;
            this.setStatus(STATE.READY, 'Ready to play.');
            this.polling = false;
            return;
        }

        if (result.status === 'error') {
            this.setStatus(STATE.ERROR, result.message || 'Audio generation failed.');
            this.polling = false;
            return;
        }

        // pending | generating | stale — keep polling.
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
        this.audio.play().catch(() => { /* user gesture required */ });
    }

    async regen() {
        try {
            this.setStatus(STATE.PENDING, 'Queued for regeneration…');
            this.playBtn.disabled = true;
            this.restartBtn.disabled = true;
            await this.callRegen();
            this.schedulePoll();
        } catch (e) {
            Notification.exception(e);
        }
    }

    renderPlaying(isPlaying) {
        this.iconPlay.classList.toggle('d-none', isPlaying);
        this.iconPause.classList.toggle('d-none', !isPlaying);
        this.playBtn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
    }

    renderTime() {
        const current = formatTime(this.audio.currentTime);
        const total = formatTime(this.audio.duration);
        this.timeEl.textContent = `${current} / ${total}`;
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

export const init = async (config) => {
    const mount = document.getElementById('local-aireader-mount');
    if (!mount) {
        return;
    }
    try {
        const {html, js} = await Templates.renderForPromise('local_aireader/player', {
            disclosure: config.disclosure || '',
        });
        Templates.replaceNodeContents(mount, html, js);

        // Reposition above the main content for the resource.
        const target = findInsertionTarget(config.module);
        if (target && target.parentNode) {
            target.parentNode.insertBefore(mount, target);
        }

        const root = mount.querySelector('.local-aireader-player');
        if (root) {
            new Player(root, config);
        }
    } catch (e) {
        Notification.exception(e);
    }
};
