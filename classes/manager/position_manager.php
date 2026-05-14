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
 * Per-user resume positions for local_aireader audio assets.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Read/write helpers for `local_aireader_position`.
 *
 * Position is stored in whole seconds keyed on `(userid, assetid)`. When
 * an asset is regenerated, content drift produces a new asset id and the
 * position naturally goes back to 0 — there is no stale-position bug to
 * worry about. When an asset is purged (cm/chapter deleted or stale GC),
 * `purge_for_asset()` cleans the corresponding rows.
 *
 * @package local_aireader
 */
class position_manager {
    /**
     * Return the user's last position on this asset, or 0 if none.
     *
     * @param int $userid
     * @param int $assetid
     * @return int Seconds.
     */
    public static function get(int $userid, int $assetid): int {
        global $DB;
        $row = $DB->get_record(
            'local_aireader_position',
            ['userid' => $userid, 'assetid' => $assetid],
            'position'
        );
        return $row ? (int)$row->position : 0;
    }

    /**
     * Upsert a position. Clamps negative values to 0.
     *
     * @param int $userid
     * @param int $assetid
     * @param int $position Seconds.
     */
    public static function set(int $userid, int $assetid, int $position): void {
        global $DB;
        $position = max(0, $position);
        $now = time();
        $existing = $DB->get_record(
            'local_aireader_position',
            ['userid' => $userid, 'assetid' => $assetid],
            'id'
        );
        if ($existing) {
            $DB->update_record('local_aireader_position', (object)[
                'id'           => $existing->id,
                'position'     => $position,
                'timemodified' => $now,
            ]);
            return;
        }
        $DB->insert_record('local_aireader_position', (object)[
            'userid'       => $userid,
            'assetid'      => $assetid,
            'position'     => $position,
            'timemodified' => $now,
        ]);
    }

    /**
     * Delete every saved position for this asset (used when the asset is
     * purged from the system).
     *
     * @param int $assetid
     */
    public static function purge_for_asset(int $assetid): void {
        global $DB;
        $DB->delete_records('local_aireader_position', ['assetid' => $assetid]);
    }

    /**
     * Delete every saved position for this user (used by the privacy provider's
     * delete-by-user request).
     *
     * @param int $userid
     */
    public static function purge_for_user(int $userid): void {
        global $DB;
        $DB->delete_records('local_aireader_position', ['userid' => $userid]);
    }
}
