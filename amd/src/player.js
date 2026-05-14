// Player AMD module for local_aireader.
//
// The PHP hook injects an empty #local-aireader-mount div near the top of the
// body. This module:
//   1. If narration is disabled at this scope AND viewer is a manager, render a
//      slim "turn it on" placeholder.
//   2. Otherwise render the full player template, reposition above the resource
//      content region, poll local_aireader_get_status until ready or error, and
//      wire play/pause/restart/regen plus a manager-only "turn off here" button.

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
        this.managerBox = root.querySelector('[data-region="manager"]');
        this.managerBtn = this.managerBox && this.managerBox.querySelector('[data-action="toggle-enabled"]');
        this.langPicker = root.querySelector('[data-region="langpicker"]');
        this.langSelect = this.langPicker && this.langPicker.querySelector('[data-action="set-lang"]');
        this.polling = false;

        if (config.canmanage) {
            this.regenBtn.classList.remove('d-none');
            this.managerBox.classList.remove('d-none');
        }
        this.populateLanguages();

        this.bindEvents();
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

        if (this.managerBtn) {
            this.managerBtn.addEventListener('click', () => this.disableHere());
        }

        if (this.langSelect) {
            this.langSelect.addEventListener('change', () => this.changeLanguage(this.langSelect.value));
        }
    }

    changeLanguage(newlang) {
        if (!newlang || newlang === this.config.lang) {
            return;
        }
        this.config.lang = newlang;
        // Reset playback state so the user knows we're switching.
        this.audio.pause();
        this.audio.removeAttribute('src');
        this.playBtn.disabled = true;
        this.restartBtn.disabled = true;
        this.setStatus(STATE.LOADING, 'Preparing in selected language…');
        this.polling = false;
        this.refresh();
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
            await this.callRegen();
            this.schedulePoll();
        } catch (e) {
            Notification.exception(e);
        }
    }

    async disableHere() {
        try {
            await setOverride(this.config, false);
            // Swap to the offline placeholder so the manager can re-enable later.
            renderOffline(this.root.parentNode, {...this.config, enabled: false});
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
                    // Reload so the server-rendered player mount picks up the change.
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

    // Reposition mount above the resource content region.
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
