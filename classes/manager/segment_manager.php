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
 * Whisper-aligned sentence segments for local_aireader audio assets.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Read/write helpers for the `local_aireader_segment` table.
 *
 * One row per Whisper segment per asset. Segments are content-derived (not
 * user-specific) so they live alongside the asset in the same lifecycle:
 * regenerating the audio → new asset id → fresh segments; purging the
 * asset → cascading purge of segments.
 *
 * @package local_aireader
 */
class segment_manager {
    /**
     * Return every stored segment for an asset, ordered by idx.
     *
     * @param int $assetid
     * @return \stdClass[] Each row: {id, assetid, idx, startms, endms, segtext}
     */
    public static function get_for_asset(int $assetid): array {
        global $DB;
        $rows = $DB->get_records(
            'local_aireader_segment',
            ['assetid' => $assetid],
            'idx ASC',
            'id, assetid, idx, startms, endms, segtext'
        );
        return array_values($rows);
    }

    /**
     * Replace all segments for an asset with the supplied list. Atomic: existing
     * rows are deleted before inserts, all inside a transaction.
     *
     * @param int   $assetid
     * @param array $segments Each entry: ['startms'=>int, 'endms'=>int, 'segtext'=>string].
     *                        Reordered to start at idx=0 in the order supplied.
     */
    public static function store_for_asset(int $assetid, array $segments): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_aireader_segment', ['assetid' => $assetid]);
            $rows = [];
            $idx = 0;
            foreach ($segments as $seg) {
                $text = trim((string)($seg['segtext'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $rows[] = (object)[
                    'assetid' => $assetid,
                    'idx'     => $idx,
                    'startms' => max(0, (int)($seg['startms'] ?? 0)),
                    'endms'   => max(0, (int)($seg['endms'] ?? 0)),
                    'segtext' => $text,
                ];
                $idx++;
            }
            if ($rows) {
                $DB->insert_records('local_aireader_segment', $rows);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Delete every segment for this asset (called from asset_manager::purge_asset).
     *
     * @param int $assetid
     */
    public static function purge_for_asset(int $assetid): void {
        global $DB;
        $DB->delete_records('local_aireader_segment', ['assetid' => $assetid]);
    }

    /**
     * Whether the asset has any aligned segments stored.
     *
     * @param int $assetid
     * @return bool
     */
    public static function has_for_asset(int $assetid): bool {
        global $DB;
        return $DB->record_exists('local_aireader_segment', ['assetid' => $assetid]);
    }
}
