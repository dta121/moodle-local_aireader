<?php
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
 * Upgrade steps for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade entry point.
 *
 * @param int $oldversion Previously-installed plugin version.
 * @return bool
 */
function xmldb_local_aireader_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026051400) {
        $table = new xmldb_table('local_aireader_override');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('chapterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('unique_scope', XMLDB_INDEX_UNIQUE, ['cmid', 'chapterid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051400, 'local', 'aireader');
    }

    if ($oldversion < 2026051401) {
        // No schema change; new scheduled task class shipped in db/tasks.php
        // and a stale_retention_days admin setting. core_plugin handles task
        // registration on upgrade.
        upgrade_plugin_savepoint(true, 2026051401, 'local', 'aireader');
    }

    if ($oldversion < 2026051402) {
        // Visual redesign of the player and offline placeholder; new styles.css.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026051402, 'local', 'aireader');
    }

    if ($oldversion < 2026051500) {
        $table = new xmldb_table('local_aireader_translation');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('texthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcelang', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetlang', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('translated', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('unique_translation', XMLDB_INDEX_UNIQUE, ['texthash', 'targetlang', 'model']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026051500, 'local', 'aireader');
    }

    if ($oldversion < 2026051501) {
        // No schema change; new admin settings for translation + enabled languages
        // need a version bump so admin_apply_default_settings() runs.
        upgrade_plugin_savepoint(true, 2026051501, 'local', 'aireader');
    }

    if ($oldversion < 2026051502) {
        // Language picker in the player UI + new CSS. No schema change; the
        // version bump is here so theme/js revs increment.
        upgrade_plugin_savepoint(true, 2026051502, 'local', 'aireader');
    }

    if ($oldversion < 2026051503) {
        // Add lastusedtime + index for LRU garbage-collection of unused translations.
        $table = new xmldb_table('local_aireader_translation');
        $field = new xmldb_field('lastusedtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Backfill existing rows so a brand-new value of 0 doesn't make them
            // immediately eligible for purge.
            $DB->execute('UPDATE {local_aireader_translation} SET lastusedtime = timemodified WHERE lastusedtime = 0');
        }
        $index = new xmldb_index('lastusedtime', XMLDB_INDEX_NOTUNIQUE, ['lastusedtime']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026051503, 'local', 'aireader');
    }

    if ($oldversion < 2026051600) {
        // Player UX: scrub bar, skip ±15s, playback speed, MediaSession,
        // keyboard shortcuts, "~N min listen" pre-play estimate. No schema
        // change; version bump so themerev/jsrev increments.
        upgrade_plugin_savepoint(true, 2026051600, 'local', 'aireader');
    }

    if ($oldversion < 2026051601) {
        $table = new xmldb_table('local_aireader_position');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('assetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('position', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('user_asset', XMLDB_INDEX_UNIQUE, ['userid', 'assetid']);
        $table->add_index('assetid', XMLDB_INDEX_NOTUNIQUE, ['assetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026051601, 'local', 'aireader');
    }

    if ($oldversion < 2026051700) {
        $table = new xmldb_table('local_aireader_segment');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('idx', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('startms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('endms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('segtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('asset_idx', XMLDB_INDEX_UNIQUE, ['assetid', 'idx']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026051700, 'local', 'aireader');
    }

    if ($oldversion < 2026051701) {
        // Whisper alignment admin settings + get_transcript web service shipped;
        // bump so admin_apply_default_settings() registers the new defaults.
        upgrade_plugin_savepoint(true, 2026051701, 'local', 'aireader');
    }

    if ($oldversion < 2026051702) {
        // The align_audio adhoc task class shipped; no schema change.
        upgrade_plugin_savepoint(true, 2026051702, 'local', 'aireader');
    }

    if ($oldversion < 2026051703) {
        // Transcript pane + in-place <mark> highlighting + get_transcript wiring
        // in the AMD module; no schema change. Bump so jsrev/themerev increments.
        upgrade_plugin_savepoint(true, 2026051703, 'local', 'aireader');
    }

    if ($oldversion < 2026051704) {
        // Hotfix: tighten in-place wrap scope so navigation/breadcrumbs are
        // never touched. JS-only change; version bump so browsers fetch
        // the fixed AMD bundle.
        upgrade_plugin_savepoint(true, 2026051704, 'local', 'aireader');
    }

    if ($oldversion < 2026051705) {
        // Broaden in-place wrap container fallbacks (now safe because the
        // walker's WRAP_REJECT_SELECTOR strictly excludes chrome). Adds
        // console.info diagnostics so we can see why in-place falls back.
        upgrade_plugin_savepoint(true, 2026051705, 'local', 'aireader');
    }

    if ($oldversion < 2026051706) {
        // Keep partial in-place marks alongside the transcript pane: lower
        // the threshold to 50% and stop rolling back successful marks when
        // ratio falls below it. Active-segment highlight now ticks marks
        // regardless of whether in-place is the "primary" visual.
        upgrade_plugin_savepoint(true, 2026051706, 'local', 'aireader');
    }

    if ($oldversion < 2026051707) {
        // CSS only: <mark> spans are now invisible at rest, with subtle
        // hover tint and full highlight only when the audio is narrating
        // that segment. Version bump so themerev rolls and clients pull
        // the fresh styles.css.
        upgrade_plugin_savepoint(true, 2026051707, 'local', 'aireader');
    }

    if ($oldversion < 2026051708) {
        // Hotfix: drop #region-main / [role="main"] fallbacks from the
        // in-place wrap container picker and add Moodle activity-chrome
        // (activity-header, completion-info, activity-information, badges)
        // to WRAP_REJECT_SELECTOR. Without this, the walker descended into
        // completion widgets whose JS broke when their text was wrapped,
        // cascading into nav/menu/player disappearing. JS-only change;
        // version bump so browsers fetch the fixed AMD bundle.
        upgrade_plugin_savepoint(true, 2026051708, 'local', 'aireader');
    }

    if ($oldversion < 2026051900) {
        // v1.0.0: security hardening pass — chapter-visibility gates, lang
        // allowlist enforcement on the external API, course/cm-scoped
        // require_login in the pluginfile handler, per-asset content size
        // cap (max_narration_chars), HTTPS-only outbound calls with private
        // IP / loopback blocking, sanitized OpenAI error snippets, narrower
        // PARAM types on web service returns, and removal of the unused
        // local/aireader:purge capability. No schema change; version bump
        // registers the new max_narration_chars admin default.
        upgrade_plugin_savepoint(true, 2026051900, 'local', 'aireader');
    }

    return true;
}
