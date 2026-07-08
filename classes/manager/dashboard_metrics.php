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
 * Aggregated usage metrics for the AI Reader admin dashboard.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Read-only, aggregate-only metric queries powering the usage dashboard.
 *
 * Every method returns counts, sums, or ratios derived from data the plugin
 * already stores — no per-user rows are exposed, so the privacy provider is
 * unaffected. "Reach" is deliberately defined as learners who have started
 * listening (they have a saved playback position); completion is not reported
 * here because {@see position_manager} resets a position to 0 at track end,
 * which would make finishers indistinguishable from non-starters.
 *
 * @package local_aireader
 */
class dashboard_metrics {
    /**
     * Asset counts keyed by status. Every status is present (0 when none).
     *
     * @return array<string,int> Keyed by asset_manager::STATUS_* values.
     */
    public static function status_breakdown(): array {
        global $DB;
        $counts = array_fill_keys([
            asset_manager::STATUS_READY,
            asset_manager::STATUS_ERROR,
            asset_manager::STATUS_GENERATING,
            asset_manager::STATUS_PENDING,
            asset_manager::STATUS_STALE,
        ], 0);
        $rows = $DB->get_records_sql(
            'SELECT status, COUNT(*) AS cnt FROM {local_aireader_asset} GROUP BY status'
        );
        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int)$row->cnt;
            }
        }
        return $counts;
    }

    /**
     * Number of distinct learners who have started listening to a ready asset.
     *
     * @return int
     */
    public static function reach(): int {
        global $DB;
        $sql = 'SELECT COUNT(DISTINCT p.userid)
                  FROM {local_aireader_position} p
                  JOIN {local_aireader_asset} a ON a.id = p.assetid
                 WHERE a.status = :ready';
        return (int)$DB->count_records_sql($sql, ['ready' => asset_manager::STATUS_READY]);
    }

    /**
     * Number of distinct course modules that have at least one ready narration.
     *
     * @return int
     */
    public static function activities_narrated(): int {
        global $DB;
        $sql = 'SELECT COUNT(DISTINCT cmid) FROM {local_aireader_asset} WHERE status = :ready';
        return (int)$DB->count_records_sql($sql, ['ready' => asset_manager::STATUS_READY]);
    }

    /**
     * Total stored bytes across ready audio assets.
     *
     * @return int
     */
    public static function storage_bytes(): int {
        global $DB;
        $sum = $DB->get_field_sql(
            'SELECT COALESCE(SUM(bytesize), 0) FROM {local_aireader_asset} WHERE status = :ready',
            ['ready' => asset_manager::STATUS_READY]
        );
        return (int)$sum;
    }

    /**
     * Count of ready assets per language, ordered by count descending.
     *
     * @return array<string,int> Language code => ready asset count.
     */
    public static function language_demand(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT lang, COUNT(*) AS cnt
               FROM {local_aireader_asset}
              WHERE status = :ready
           GROUP BY lang
           ORDER BY cnt DESC, lang ASC',
            ['ready' => asset_manager::STATUS_READY]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->lang] = (int)$row->cnt;
        }
        return $out;
    }

    /**
     * Total minutes of ready audio.
     *
     * Uses each asset's stored duration, falling back to the last aligned
     * segment's end time (Whisper alignment) when duration was not recorded,
     * and 0 when neither is available. Bucketing is done in PHP to stay
     * portable across PostgreSQL and MariaDB.
     *
     * @return float Minutes, rounded to one decimal place.
     */
    public static function audio_minutes(): float {
        global $DB;
        $assets = $DB->get_records(
            'local_aireader_asset',
            ['status' => asset_manager::STATUS_READY],
            '',
            'id, durationsecs'
        );
        if (!$assets) {
            return 0.0;
        }
        $segmax = $DB->get_records_sql(
            'SELECT assetid, MAX(endms) AS maxendms
               FROM {local_aireader_segment}
           GROUP BY assetid'
        );
        $secs = 0;
        foreach ($assets as $asset) {
            if (!empty($asset->durationsecs)) {
                $secs += (int)$asset->durationsecs;
            } else if (isset($segmax[$asset->id])) {
                $secs += (int)round((int)$segmax[$asset->id]->maxendms / 1000);
            }
        }
        return round($secs / 60, 1);
    }

    /**
     * Ready-asset generation counts bucketed by calendar month (YYYY-MM),
     * sorted chronologically. Suitable for an adoption-over-time line chart.
     *
     * @return array<string,int> 'YYYY-MM' => count.
     */
    public static function adoption_over_time(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id, lastgenerated, timecreated
               FROM {local_aireader_asset}
              WHERE status = :ready',
            ['ready' => asset_manager::STATUS_READY]
        );
        $buckets = [];
        foreach ($rows as $row) {
            $when = (int)($row->lastgenerated ?: $row->timecreated);
            if ($when <= 0) {
                continue;
            }
            $key = date('Y-m', $when);
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }
        ksort($buckets);
        return $buckets;
    }

    /**
     * Number of scopes (activities or chapters) where an instructor has turned
     * narration off.
     *
     * @return int
     */
    public static function instructor_optouts(): int {
        global $DB;
        return $DB->count_records('local_aireader_override', ['enabled' => 0]);
    }

    /**
     * Generation failure rate as a percentage of terminal (ready + error) assets.
     *
     * @param array|null $status Optional pre-fetched status breakdown.
     * @return float|null Percentage 0–100, or null when there are no terminal assets.
     */
    public static function failure_percent(?array $status = null): ?float {
        $status = $status ?? self::status_breakdown();
        $ready = (int)($status[asset_manager::STATUS_READY] ?? 0);
        $error = (int)($status[asset_manager::STATUS_ERROR] ?? 0);
        $denom = $ready + $error;
        if ($denom === 0) {
            return null;
        }
        return round($error / $denom * 100, 1);
    }

    /**
     * Headline KPI summary for the dashboard strip.
     *
     * @return \stdClass {totalassets:int, readyassets:int, audiominutes:float,
     *                    estcost:float, hasunknowncost:bool, reach:int,
     *                    activitiesnarrated:int, failurepercent:float|null,
     *                    storagebytes:int, optouts:int}
     */
    public static function site_summary(): \stdClass {
        $status = self::status_breakdown();
        $totals = cost_report::grand_totals();
        return (object)[
            'totalassets'        => (int)$totals->total,
            'readyassets'        => (int)($status[asset_manager::STATUS_READY] ?? 0),
            'audiominutes'       => self::audio_minutes(),
            'estcost'            => (float)$totals->cost,
            'hasunknowncost'     => (bool)$totals->hasunknowncost,
            'reach'              => self::reach(),
            'activitiesnarrated' => self::activities_narrated(),
            'failurepercent'     => self::failure_percent($status),
            'storagebytes'       => self::storage_bytes(),
            'optouts'            => self::instructor_optouts(),
        ];
    }
}
