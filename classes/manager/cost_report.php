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
 * Aggregated cost rollups over generated narration assets.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Builds cost rollups (site-wide totals, per-course totals) from the asset
 * table. Costs are date-effective per {@see cost_calculator}, so each asset is
 * priced at the rate in force when it was generated; that means the totals are
 * accumulated in PHP rather than summed in SQL.
 *
 * @package local_aireader
 */
class cost_report {
    /**
     * Site-wide totals: asset counts by status and the estimated total spend.
     *
     * @return \stdClass {total:int, ready:int, failed:int, pending:int,
     *                    stale:int, cost:float, hasunknowncost:bool}
     */
    public static function grand_totals(): \stdClass {
        global $DB;

        $counts = array_fill_keys(
            [
                asset_manager::STATUS_READY,
                asset_manager::STATUS_ERROR,
                asset_manager::STATUS_GENERATING,
                asset_manager::STATUS_PENDING,
                asset_manager::STATUS_STALE,
            ],
            0
        );
        $statusrows = $DB->get_records_sql(
            'SELECT status, COUNT(*) AS cnt FROM {local_aireader_asset} GROUP BY status'
        );
        $total = 0;
        foreach ($statusrows as $row) {
            $total += (int)$row->cnt;
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int)$row->cnt;
            }
        }

        $cost = 0.0;
        $hasunknown = false;
        $rs = $DB->get_recordset(
            'local_aireader_asset',
            ['status' => asset_manager::STATUS_READY],
            '',
            'id, model, inputchars, lastgenerated, timecreated'
        );
        foreach ($rs as $r) {
            $usd = self::asset_cost($r);
            if ($usd === null) {
                $hasunknown = true;
            } else {
                $cost += $usd;
            }
        }
        $rs->close();

        return (object)[
            'total'          => $total,
            'ready'          => $counts[asset_manager::STATUS_READY],
            'failed'         => $counts[asset_manager::STATUS_ERROR],
            'pending'        => $counts[asset_manager::STATUS_PENDING]
                                + $counts[asset_manager::STATUS_GENERATING],
            'stale'          => $counts[asset_manager::STATUS_STALE],
            'cost'           => $cost,
            'hasunknowncost' => $hasunknown,
        ];
    }

    /**
     * Per-course rollup: one entry per course that has any asset, ordered by
     * estimated cost descending.
     *
     * @return \stdClass[] Each: {courseid:int, coursename:string, assets:int,
     *                     ready:int, cost:float, hasunknowncost:bool}
     */
    public static function totals_by_course(): array {
        global $DB;

        $sql = 'SELECT a.id, a.courseid, c.fullname AS coursename, a.module, a.status,
                       a.inputchars, a.lastgenerated, a.timecreated
                  FROM {local_aireader_asset} a
                  JOIN {course} c ON c.id = a.courseid
              ORDER BY a.courseid';
        $rs = $DB->get_recordset_sql($sql);

        $courses = [];
        foreach ($rs as $r) {
            $cid = (int)$r->courseid;
            if (!isset($courses[$cid])) {
                $courses[$cid] = (object)[
                    'courseid'       => $cid,
                    'coursename'     => $r->coursename,
                    'assets'         => 0,
                    'ready'          => 0,
                    'cost'           => 0.0,
                    'hasunknowncost' => false,
                ];
            }
            $entry = $courses[$cid];
            $entry->assets++;
            if ($r->status === asset_manager::STATUS_READY) {
                $entry->ready++;
                $usd = self::asset_cost($r);
                if ($usd === null) {
                    $entry->hasunknowncost = true;
                } else {
                    $entry->cost += $usd;
                }
            }
        }
        $rs->close();

        $courses = array_values($courses);
        usort($courses, static function (\stdClass $a, \stdClass $b): int {
            return $b->cost <=> $a->cost;
        });
        return $courses;
    }

    /**
     * Estimate one asset row's cost at its generation time.
     *
     * @param \stdClass $r Row with model, inputchars, lastgenerated, timecreated.
     * @return float|null
     */
    private static function asset_cost(\stdClass $r): ?float {
        $attime = (int)($r->lastgenerated ?: $r->timecreated);
        $chars = $r->inputchars !== null ? (int)$r->inputchars : null;
        return cost_calculator::estimate_usd($r->model, $chars, $attime);
    }
}
