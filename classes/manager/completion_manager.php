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
 * Listening-progress activity completion helpers.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Stores listened ranges and completes activities when thresholds are reached.
 *
 * This intentionally tracks distinct audio ranges rather than the last playback
 * position. A learner can scrub to 90% of the file and save a resume position,
 * but they only satisfy listening completion for the ranges that actually
 * played through the embedded Moodle player.
 *
 * @package local_aireader
 */
class completion_manager {
    /** Default required listen percentage on newly enabled activities. */
    public const DEFAULT_THRESHOLD = 80;

    /** Small merge tolerance for adjacent browser timeupdate ranges. */
    private const MERGE_GAP_MS = 250;

    /**
     * Whether admins allow teachers to enable AI Reader completion rules.
     *
     * @return bool
     */
    public static function site_enabled(): bool {
        return get_config('local_aireader', 'enable_completion') === '1';
    }

    /**
     * Clamp a teacher-entered percentage to the supported range.
     *
     * @param int $threshold
     * @return int
     */
    public static function normalize_threshold(int $threshold): int {
        return max(1, min(100, $threshold));
    }

    /**
     * Return the per-CM completion config, if one exists.
     *
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get_config_for_cm(int $cmid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_aireader_completion', ['cmid' => $cmid]);
        return $record ?: null;
    }

    /**
     * Store the teacher's completion setting for a course module.
     *
     * @param int $courseid
     * @param int $cmid
     * @param bool $enabled
     * @param int $threshold
     * @param int $usermodified
     */
    public static function set_config(
        int $courseid,
        int $cmid,
        bool $enabled,
        int $threshold,
        int $usermodified
    ): void {
        global $DB;

        $threshold = self::normalize_threshold($threshold);
        $now = time();
        $existing = $DB->get_record('local_aireader_completion', ['cmid' => $cmid], 'id');

        $record = (object)[
            'courseid'     => $courseid,
            'cmid'         => $cmid,
            'enabled'      => $enabled ? 1 : 0,
            'threshold'    => $threshold,
            'timemodified' => $now,
            'usermodified' => $usermodified,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_aireader_completion', $record);
            return;
        }

        $DB->insert_record('local_aireader_completion', $record);
    }

    /**
     * Merge a newly played range into the user's listened ranges for an asset.
     *
     * Ranges use millisecond offsets and are stored as [startms, endms). The
     * merge prevents replaying the same section from inflating completion.
     *
     * @param int $userid
     * @param \stdClass $asset
     * @param int $startms
     * @param int $endms
     */
    public static function record_range(int $userid, \stdClass $asset, int $startms, int $endms): void {
        global $DB;

        if ((string)$asset->status !== asset_manager::STATUS_READY) {
            return;
        }

        $startms = max(0, $startms);
        $endms = max(0, $endms);
        if (!empty($asset->durationsecs)) {
            $durationms = max(0, (int)$asset->durationsecs * 1000);
            $startms = min($startms, $durationms);
            $endms = min($endms, $durationms);
        }
        if ($endms <= $startms) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        $params = [
            'userid' => $userid,
            'assetid' => (int)$asset->id,
            'startms' => $startms - self::MERGE_GAP_MS,
            'endms' => $endms + self::MERGE_GAP_MS,
        ];
        $rows = $DB->get_records_select(
            'local_aireader_listen',
            'userid = :userid AND assetid = :assetid AND endms >= :startms AND startms <= :endms',
            $params
        );

        $mergedstart = $startms;
        $mergedend = $endms;
        $deleteids = [];
        foreach ($rows as $row) {
            $mergedstart = min($mergedstart, (int)$row->startms);
            $mergedend = max($mergedend, (int)$row->endms);
            $deleteids[] = (int)$row->id;
        }

        if ($deleteids) {
            [$insql, $inparams] = $DB->get_in_or_equal($deleteids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_aireader_listen', "id {$insql}", $inparams);
        }

        $DB->insert_record('local_aireader_listen', (object)[
            'userid'       => $userid,
            'assetid'      => (int)$asset->id,
            'startms'      => $mergedstart,
            'endms'        => $mergedend,
            'timemodified' => time(),
        ]);

        $transaction->allow_commit();
    }

    /**
     * Return distinct listened milliseconds for a user and asset.
     *
     * @param int $userid
     * @param int $assetid
     * @return int
     */
    public static function listened_ms(int $userid, int $assetid): int {
        global $DB;

        $sum = $DB->get_field_sql(
            'SELECT COALESCE(SUM(endms - startms), 0)
               FROM {local_aireader_listen}
              WHERE userid = :userid AND assetid = :assetid',
            ['userid' => $userid, 'assetid' => $assetid]
        );

        return max(0, (int)$sum);
    }

    /**
     * Calculate and, if eligible, apply Moodle activity completion.
     *
     * @param int $userid
     * @param \stdClass $asset
     * @return array{enabled:bool,threshold:int,listenedms:int,percent:int,completed:bool}
     */
    public static function maybe_complete(int $userid, \stdClass $asset): array {
        $config = self::get_config_for_cm((int)$asset->cmid);
        $threshold = $config ? self::normalize_threshold((int)$config->threshold) : self::DEFAULT_THRESHOLD;
        $listenedms = self::listened_ms($userid, (int)$asset->id);
        $durationms = !empty($asset->durationsecs) ? (int)$asset->durationsecs * 1000 : 0;
        $percent = $durationms > 0 ? (int)floor(min(100, ($listenedms / $durationms) * 100)) : 0;

        $result = [
            'enabled' => false,
            'threshold' => $threshold,
            'listenedms' => $listenedms,
            'percent' => $percent,
            'completed' => false,
        ];

        if (!self::site_enabled() || !$config || empty($config->enabled) || $durationms <= 0) {
            return $result;
        }

        $result['enabled'] = true;
        $requiredms = (int)ceil($durationms * ($threshold / 100));
        if ($listenedms < $requiredms) {
            return $result;
        }

        $result['completed'] = self::mark_activity_complete($userid, $asset);
        return $result;
    }

    /**
     * Mark the source course module complete for the user.
     *
     * This writes the same completion table through Moodle's public completion
     * helper so events, caches, and course-completion aggregation are refreshed.
     *
     * @param int $userid
     * @param \stdClass $asset
     * @return bool Whether the activity is now complete or was already complete.
     */
    private static function mark_activity_complete(int $userid, \stdClass $asset): bool {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        [$course, $cm] = get_course_and_cm_from_cmid((int)$asset->cmid, (string)$asset->module);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled($cm)) {
            return false;
        }

        $data = $completion->get_data($cm, false, $userid);
        $completestates = [
            COMPLETION_COMPLETE,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_FAIL,
        ];
        if (in_array((int)$data->completionstate, $completestates, true)) {
            return true;
        }

        $data->completionstate = COMPLETION_COMPLETE;
        $data->timemodified = time();
        $data->overrideby = null;
        $completion->internal_set_data($cm, $data);

        return true;
    }

    /**
     * Delete all listened ranges for an asset.
     *
     * @param int $assetid
     */
    public static function purge_for_asset(int $assetid): void {
        global $DB;
        $DB->delete_records('local_aireader_listen', ['assetid' => $assetid]);
    }

    /**
     * Delete all listened ranges for a user.
     *
     * @param int $userid
     */
    public static function purge_for_user(int $userid): void {
        global $DB;
        $DB->delete_records('local_aireader_listen', ['userid' => $userid]);
    }
}
