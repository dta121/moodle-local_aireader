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
 * Redesigned admin settings page for local_aireader.
 *
 * Progressive enhancement over the native Moodle admin settings form: the
 * form and its inputs stay the source of truth (values, validation, saving),
 * while this module regroups the rendered rows into section cards, adds a
 * sticky sidebar with filter + scrollspy nav, collapses advanced settings per
 * section, renders status chips, decorates rows with Modified badges and
 * default/reset lines, turns multicheckbox settings into chip lists with an
 * expandable grid, and pins the save bar to the bottom of the viewport.
 *
 * If the expected DOM is missing (theme override, future Moodle change), the
 * module bails out and the stock settings page renders untouched.
 *
 * @module     local_aireader/admin_settings
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const tpl = (template, value) => String(template || '').replace('%%', String(value));

const normalise = (value) => {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value).replace(/\r\n/g, '\n');
};

/**
 * The option key of a multicheckbox input. Moodle names these
 * "s_plugin_setting[key]" with value="1", so the key lives in the name.
 *
 * @param {HTMLInputElement} box A checkbox inside .form-multicheckbox.
 * @returns {string} The option key, or '' when it cannot be parsed.
 */
const multiKey = (box) => {
    const match = /\[([^\]]+)\]$/.exec(box.name || '');
    return match ? match[1] : '';
};

/**
 * The canonical current value of a settings row, comparable against the
 * canonical default the PHP side sent.
 *
 * @param {HTMLElement} row The .form-item row element.
 * @param {object} meta Row metadata from PHP.
 * @returns {string|null} Canonical value, or null when it cannot be read.
 */
const currentValue = (row, meta) => {
    if (meta.type === 'checkbox') {
        const box = row.querySelector('.form-setting input[type="checkbox"]');
        if (!box) {
            return null;
        }
        return box.checked ? '1' : '0';
    }
    if (meta.type === 'multi') {
        const boxes = row.querySelectorAll('.form-setting input[type="checkbox"]');
        if (!boxes.length) {
            return null;
        }
        const on = Array.from(boxes).filter((b) => b.checked).map(multiKey).filter(Boolean);
        on.sort();
        return on.join(',');
    }
    const input = row.querySelector(
        '.form-setting textarea, .form-setting select, ' +
        '.form-setting input[type="text"], .form-setting input:not([type="hidden"]):not([type="checkbox"])'
    );
    return input ? normalise(input.value) : null;
};

const canonicalDefault = (meta) => {
    if (meta.reset === null || meta.reset === undefined) {
        return null;
    }
    if (Array.isArray(meta.reset)) {
        const keys = meta.reset.slice();
        keys.sort();
        return keys.join(',');
    }
    return normalise(meta.reset);
};

/**
 * Set a row's inputs back to the shipped default and fire change events so
 * both Moodle behaviours and our own recount listeners react.
 *
 * @param {HTMLElement} row The .form-item row element.
 * @param {object} meta Row metadata from PHP.
 */
const applyReset = (row, meta) => {
    const fire = (el) => {
        el.dispatchEvent(new Event('input', {bubbles: true}));
        el.dispatchEvent(new Event('change', {bubbles: true}));
    };
    if (meta.type === 'checkbox') {
        const box = row.querySelector('.form-setting input[type="checkbox"]');
        if (box) {
            box.checked = meta.reset === '1';
            fire(box);
        }
        return;
    }
    if (meta.type === 'multi') {
        const wanted = Array.isArray(meta.reset) ? meta.reset : [];
        row.querySelectorAll('.form-setting input[type="checkbox"]').forEach((box) => {
            const target = wanted.indexOf(multiKey(box)) !== -1;
            if (box.checked !== target) {
                box.checked = target;
                fire(box);
            }
        });
        return;
    }
    const input = row.querySelector(
        '.form-setting textarea, .form-setting select, ' +
        '.form-setting input[type="text"], .form-setting input:not([type="hidden"]):not([type="checkbox"])'
    );
    if (input) {
        input.value = String(meta.reset);
        fire(input);
    }
};

class AdminUi {
    constructor(cfg, form, container) {
        this.cfg = cfg;
        this.strings = cfg.strings || {};
        this.form = form;
        this.container = container;
        this.sections = [];
        this.rowsByName = new Map();
        this.filterValue = '';
    }

    settingName(row) {
        return (row.id || '').replace(/^admin-/, '');
    }

    meta(row) {
        return (this.cfg.settings || {})[this.settingName(row)] || null;
    }

    build() {
        this.collectSections();
        if (!this.sections.length) {
            return false;
        }
        this.buildLayout();
        this.decorateRows();
        this.buildChips();
        this.buildSidebar();
        this.buildSaveBar();
        this.bindRecount();
        this.bindScrollSpy();
        this.container.classList.add('la-admin-ready');
        return true;
    }

    /**
     * Walk the settings form and group heading + rows runs into sections.
     */
    collectSections() {
        const children = Array.from(this.container.children);
        let current = null;
        children.forEach((el) => {
            const heading = el.matches('h3') ? el : el.querySelector(':scope > h3, :scope h3.main');
            if (heading && !el.classList.contains('form-item')) {
                current = {titleEl: el, descEl: null, rows: [], nodes: []};
                this.sections.push(current);
                return;
            }
            if (!current) {
                return;
            }
            if (el.classList.contains('form-item')) {
                current.rows.push(el);
                this.rowsByName.set(this.settingName(el), el);
            } else if (!current.rows.length && !current.descEl
                    && (el.classList.contains('formsettingheading') || el.classList.contains('generalbox'))) {
                current.descEl = el;
            } else {
                current.nodes.push(el);
            }
        });
        this.sections = this.sections.filter((s) => s.rows.length > 0);
    }

    buildLayout() {
        const advancedNames = this.cfg.advanced || [];

        this.layout = document.createElement('div');
        this.layout.className = 'la-layout';
        this.aside = document.createElement('aside');
        this.aside.className = 'la-aside';
        this.main = document.createElement('div');
        this.main.className = 'la-main';
        this.layout.appendChild(this.aside);
        this.layout.appendChild(this.main);

        this.sections.forEach((section, idx) => {
            const card = document.createElement('section');
            card.className = 'la-card';
            card.id = 'la-sec-' + idx;

            const head = document.createElement('div');
            head.className = 'la-card-head';
            section.title = section.titleEl.textContent.trim();
            head.appendChild(section.titleEl);
            if (section.descEl) {
                head.appendChild(section.descEl);
            }
            card.appendChild(head);

            const body = document.createElement('div');
            body.className = 'la-card-body';
            card.appendChild(body);

            const adv = document.createElement('div');
            adv.className = 'la-adv';
            adv.hidden = true;

            section.rows.forEach((row) => {
                const target = advancedNames.indexOf(this.settingName(row)) !== -1 ? adv : body;
                target.appendChild(row);
            });
            section.nodes.forEach((el) => body.appendChild(el));

            if (adv.children.length) {
                card.appendChild(adv);
                const foot = document.createElement('div');
                foot.className = 'la-adv-foot';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'la-linkbtn';
                btn.textContent = tpl(this.strings.showadvanced, adv.children.length);
                btn.addEventListener('click', () => {
                    adv.hidden = !adv.hidden;
                    btn.textContent = adv.hidden
                        ? tpl(this.strings.showadvanced, adv.children.length)
                        : this.strings.hideadvanced;
                });
                foot.appendChild(btn);
                card.appendChild(foot);
                section.advEl = adv;
            }

            section.card = card;
            this.main.appendChild(card);
        });

        this.noResults = document.createElement('div');
        this.noResults.className = 'la-noresults';
        this.noResults.textContent = this.strings.noresults;
        this.noResults.hidden = true;
        this.main.appendChild(this.noResults);

        this.container.appendChild(this.layout);
    }

    decorateRows() {
        this.rowsByName.forEach((row, name) => {
            const meta = (this.cfg.settings || {})[name];
            if (!meta) {
                return;
            }
            const label = row.querySelector('.form-label');
            if (label && meta.type !== 'secret') {
                const badge = document.createElement('span');
                badge.className = 'la-badge';
                badge.textContent = this.strings.modified;
                badge.hidden = !meta.modified;
                const labelel = label.querySelector('label') || label;
                labelel.insertAdjacentElement('afterend', badge);
                row.laBadge = badge;

                const line = document.createElement('div');
                line.className = 'la-default';
                line.appendChild(document.createTextNode(tpl(this.strings.default, meta.defaultlabel)));
                if (meta.reset !== null && meta.reset !== undefined) {
                    const sep = document.createElement('span');
                    sep.textContent = ' · ';
                    const reset = document.createElement('button');
                    reset.type = 'button';
                    reset.className = 'la-linkbtn la-reset';
                    reset.textContent = this.strings.reset;
                    reset.hidden = !meta.modified;
                    reset.addEventListener('click', () => applyReset(row, meta));
                    sep.hidden = !meta.modified;
                    line.appendChild(sep);
                    line.appendChild(reset);
                    row.laReset = reset;
                    row.laResetSep = sep;
                }
                label.appendChild(line);
            }
            if (meta.type === 'secret' && meta.secretset) {
                const setting = row.querySelector('.form-setting');
                if (setting) {
                    const pill = document.createElement('span');
                    pill.className = 'la-pill';
                    pill.textContent = this.strings.configured;
                    setting.insertBefore(pill, setting.firstChild);
                }
            }
            if (meta.type === 'multi') {
                this.buildChipsForMulti(row, name);
            }
        });
    }

    /**
     * Replace a multicheckbox's visible grid with a chip list plus an
     * expandable panel holding the native checkboxes.
     *
     * @param {HTMLElement} row The .form-item row.
     * @param {string} name Setting name.
     */
    buildChipsForMulti(row, name) {
        const group = row.querySelector('.form-multicheckbox');
        if (!group) {
            return;
        }
        const boxes = Array.from(group.querySelectorAll('input[type="checkbox"]')).filter((b) => multiKey(b) !== '');
        if (!boxes.length) {
            return;
        }
        group.classList.add('la-multigrid');
        group.hidden = true;

        const chips = document.createElement('div');
        chips.className = 'la-chiplist';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'la-linkbtn';
        toggle.textContent = tpl(this.strings.editlist, boxes.length);
        toggle.addEventListener('click', () => {
            group.hidden = !group.hidden;
            toggle.textContent = group.hidden ? tpl(this.strings.editlist, boxes.length) : this.strings.hidelist;
        });
        const toggleWrap = document.createElement('div');
        toggleWrap.className = 'la-chiplist-toggle';
        toggleWrap.appendChild(toggle);

        group.parentNode.insertBefore(chips, group);
        group.parentNode.insertBefore(toggleWrap, group);

        const labelFor = (box) => {
            const label = box.closest('label') || (box.id ? group.querySelector('label[for="' + box.id + '"]') : null);
            return label ? label.textContent.trim() : multiKey(box);
        };
        const render = () => {
            chips.textContent = '';
            boxes.filter((b) => b.checked).forEach((box) => {
                const chip = document.createElement('span');
                chip.className = 'la-chip';
                chip.appendChild(document.createTextNode(labelFor(box)));
                if (name === 'enabled_languages' && multiKey(box) === this.cfg.sourcelang) {
                    const tag = document.createElement('span');
                    tag.className = 'la-chip-tag';
                    tag.textContent = this.strings.source;
                    chip.appendChild(tag);
                }
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'la-chip-x';
                remove.setAttribute('aria-label', labelFor(box));
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    box.checked = false;
                    box.dispatchEvent(new Event('change', {bubbles: true}));
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        };
        group.addEventListener('change', render);
        render();
    }

    buildChips() {
        const chips = this.cfg.chips || [];
        if (!chips.length) {
            return;
        }
        const bar = document.createElement('div');
        bar.className = 'la-statuschips';
        chips.forEach((c) => {
            const chip = document.createElement('span');
            chip.className = 'la-statuschip' + (c.good ? ' la-good' : '');
            const dot = document.createElement('span');
            dot.className = 'la-dot';
            chip.appendChild(dot);
            chip.appendChild(document.createTextNode(c.text));
            bar.appendChild(chip);
        });
        this.container.insertBefore(bar, this.layout);
    }

    buildSidebar() {
        const filter = document.createElement('input');
        filter.type = 'text';
        filter.className = 'la-filter';
        filter.placeholder = this.strings.filter;
        filter.addEventListener('input', () => {
            this.filterValue = filter.value.trim().toLowerCase();
            this.applyFilter();
        });
        this.aside.appendChild(filter);

        const nav = document.createElement('nav');
        nav.className = 'la-nav';
        this.sections.forEach((section, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'la-navbtn';
            const label = document.createElement('span');
            label.textContent = section.title;
            const count = document.createElement('span');
            count.className = 'la-navcount';
            count.textContent = String(section.rows.length);
            btn.appendChild(label);
            btn.appendChild(count);
            btn.addEventListener('click', () => {
                this.setActive(idx);
                const top = section.card.getBoundingClientRect().top + window.scrollY - 70;
                window.scrollTo({top: top, behavior: 'smooth'});
            });
            nav.appendChild(btn);
            section.navBtn = btn;
        });
        this.aside.appendChild(nav);

        const note = document.createElement('p');
        note.className = 'la-aside-note';
        note.textContent = this.strings.sidebarnote;
        this.aside.appendChild(note);
        this.setActive(0);
    }

    setActive(idx) {
        this.sections.forEach((s, i) => {
            if (s.navBtn) {
                s.navBtn.classList.toggle('la-active', i === idx);
            }
        });
    }

    buildSaveBar() {
        const submit = this.form.querySelector('button[type="submit"], input[type="submit"]');
        if (!submit) {
            return;
        }
        // The submit row lives outside the settings fieldset; remember its
        // wrapper so the emptied husk can be removed once the button moves.
        const oldwrap = submit.closest('.row');
        const bar = document.createElement('div');
        bar.className = 'la-savebar';
        this.modText = document.createElement('span');
        this.modText.className = 'la-modtext';
        const buttons = document.createElement('div');
        buttons.className = 'la-savebar-buttons';
        const discard = document.createElement('button');
        discard.type = 'button';
        discard.className = 'btn la-discard';
        discard.textContent = this.strings.discard;
        discard.addEventListener('click', () => window.location.reload());
        buttons.appendChild(discard);
        buttons.appendChild(submit);
        bar.appendChild(this.modText);
        bar.appendChild(buttons);
        this.main.appendChild(bar);
        if (oldwrap && oldwrap !== bar && oldwrap.textContent.trim() === ''
                && !oldwrap.querySelector('button, input, select, textarea')) {
            oldwrap.remove();
        }
        this.recount();
    }

    recount() {
        let count = 0;
        this.rowsByName.forEach((row, name) => {
            const meta = (this.cfg.settings || {})[name];
            if (!meta || meta.type === 'secret') {
                return;
            }
            const def = canonicalDefault(meta);
            const val = def === null ? null : currentValue(row, meta);
            const modified = val === null ? !!meta.modified : val !== def;
            if (modified) {
                count++;
            }
            if (row.laBadge) {
                row.laBadge.hidden = !modified;
            }
            if (row.laReset) {
                row.laReset.hidden = !modified;
                row.laResetSep.hidden = !modified;
            }
        });
        if (this.modText) {
            this.modText.textContent = tpl(this.strings.modcount, count);
        }
    }

    bindRecount() {
        let timer = null;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(() => this.recount(), 150);
        };
        this.form.addEventListener('input', schedule);
        this.form.addEventListener('change', schedule);
    }

    applyFilter() {
        const q = this.filterValue;
        let anyVisible = false;
        this.sections.forEach((section) => {
            let visible = 0;
            section.rows.forEach((row) => {
                if (!row.laHaystack) {
                    row.laHaystack = row.textContent.toLowerCase();
                }
                const match = !q || row.laHaystack.indexOf(q) !== -1;
                row.classList.toggle('la-hidden', !match);
                if (match) {
                    visible++;
                }
            });
            // Reveal advanced rows while a filter is active so matches show.
            if (section.advEl && q) {
                section.advEl.hidden = false;
            }
            section.card.classList.toggle('la-hidden', visible === 0);
            if (visible > 0) {
                anyVisible = true;
            }
        });
        this.noResults.hidden = anyVisible || !q;
    }

    bindScrollSpy() {
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(() => {
                ticking = false;
                let active = 0;
                this.sections.forEach((section, idx) => {
                    if (section.card.getBoundingClientRect().top < 150) {
                        active = idx;
                    }
                });
                this.setActive(active);
            });
        }, {passive: true});
    }
}

export const init = (cfg) => {
    const boot = () => {
        const form = document.getElementById('adminsettings');
        if (!form) {
            return;
        }
        // Moodle renders the settings run (headings + rows) inside a fieldset
        // within .settingsform; fall back for themes that flatten it.
        const container = form.querySelector('.settingsform fieldset')
            || form.querySelector('.settingsform')
            || form;
        try {
            new AdminUi(cfg, form, container).build();
        } catch (e) {
            // Progressive enhancement only: leave the stock form untouched.
            window.console && window.console.warn('local_aireader admin UI skipped:', e);
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
};
