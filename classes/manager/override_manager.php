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
 * Per-resource enable/disable override storage for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Read/write helpers for the local_aireader_override table.
 *
 * Resolution chain for "is narration enabled for (cmid, chapterid)":
 *   1. Row keyed on (cmid, chapterid > 0) — chapter-specific override
 *   2. Row keyed on (cmid, 0) — activity-level default set on the page/book
 *   3. Plugin global setting (`enable_page` or `enable_book`)
 *
 * Chapter id 0 in storage means "activity-level default". The schema stores
 * chapterid as NOT NULL with default 0 so the UNIQUE(cmid, chapterid) index is
 * portable across MariaDB and PostgreSQL (which differ on multiple NULL handling
 * in unique indexes).
 *
 * @package local_aireader
 */
class override_manager {
    /**
     * Look up an override for an exact (cmid, chapterid) tuple.
     *
     * @param int $cmid Course module id.
     * @param int $chapterid Chapter id, or 0 for the activity-level default.
     * @return bool|null true/false if set, null if no row exists.
     */
    public static function get(int $cmid, int $chapterid = 0): ?bool {
        global $DB;
        $row = $DB->get_record('local_aireader_override', [
            'cmid'      => $cmid,
            'chapterid' => $chapterid,
        ], 'enabled');
        if (!$row) {
            return null;
        }
        return (bool)$row->enabled;
    }

    /**
     * Resolve whether narration should be enabled for the given scope.
     *
     * @param int    $cmid Course module id.
     * @param int    $chapterid Chapter id, or 0 for the activity-level scope.
     * @param string $module Module name (page or book), used to choose the global default.
     * @return bool
     */
    public static function is_enabled(int $cmid, int $chapterid, string $module): bool {
        // Chapter-specific override wins.
        if ($chapterid > 0) {
            $chapterval = self::get($cmid, $chapterid);
            if ($chapterval !== null) {
                return $chapterval;
            }
        }
        $cmval = self::get($cmid, 0);
        if ($cmval !== null) {
            return $cmval;
        }
        $globalkey = $module === 'book' ? 'enable_book' : 'enable_page';
        return (bool)get_config('local_aireader', $globalkey);
    }

    /**
     * Upsert an override.
     *
     * @param int  $courseid Course id (kept for FK + analytics).
     * @param int  $cmid Course module id.
     * @param int  $chapterid Chapter id, or 0 for the activity-level scope.
     * @param bool $enabled Whether narration is enabled.
     */
    public static function set(int $courseid, int $cmid, int $chapterid, bool $enabled): void {
        global $DB, $USER;
        $now = time();

        $existing = $DB->get_record('local_aireader_override', [
            'cmid'      => $cmid,
            'chapterid' => $chapterid,
        ]);
        if ($existing) {
            $DB->update_record('local_aireader_override', (object)[
                'id'           => $existing->id,
                'enabled'      => $enabled ? 1 : 0,
                'timemodified' => $now,
                'usermodified' => (int)$USER->id,
            ]);
            return;
        }
        $DB->insert_record('local_aireader_override', (object)[
            'courseid'     => $courseid,
            'cmid'         => $cmid,
            'chapterid'    => $chapterid,
            'enabled'      => $enabled ? 1 : 0,
            'timemodified' => $now,
            'usermodified' => (int)$USER->id,
        ]);
    }

    /**
     * Delete every override for a course module (used on cm delete).
     *
     * @param int $cmid Course module id.
     */
    public static function purge_cm(int $cmid): void {
        global $DB;
        $DB->delete_records('local_aireader_override', ['cmid' => $cmid]);
    }

    /**
     * Delete the override for a single chapter (used on chapter delete).
     *
     * @param int $chapterid Book chapter id.
     */
    public static function purge_chapter(int $chapterid): void {
        global $DB;
        $DB->delete_records('local_aireader_override', ['chapterid' => $chapterid]);
    }
}
