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
 * Asset row lifecycle helpers for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

use core\task\manager as task_manager;
use local_aireader\task\generate_audio;

/**
 * Lifecycle for local_aireader_asset rows and the files they own.
 *
 * @package local_aireader
 */
class asset_manager {
    /** Pending generation. */
    public const STATUS_PENDING = 'pending';
    /** Generation in progress. */
    public const STATUS_GENERATING = 'generating';
    /** Ready to play. */
    public const STATUS_READY = 'ready';
    /** Generation failed. */
    public const STATUS_ERROR = 'error';
    /** Stale - content changed; superseded by a new row. */
    public const STATUS_STALE = 'stale';

    /**
     * Compute the canonical source hash for the (cm, chapter, voice, model, lang) variant.
     *
     * @param string $module Module name.
     * @param int $cmid Course module id.
     * @param int|null $chapterid Book chapter id, or null.
     * @param string $lang Language code.
     * @param string $voice Voice name.
     * @param string $model Model id.
     * @param string $cleantext Extracted narration-ready text.
     * @return string 64-char hex SHA256.
     */
    public static function compute_hash(
        string $module,
        int $cmid,
        ?int $chapterid,
        string $lang,
        string $voice,
        string $model,
        string $cleantext
    ): string {
        $payload = implode('|', [
            $module,
            (string)$cmid,
            $chapterid === null ? '' : (string)$chapterid,
            $lang,
            $voice,
            $model,
            $cleantext,
        ]);
        return hash('sha256', $payload);
    }

    /**
     * Look up the asset row for the requested variant, ignoring stale ones.
     *
     * @param int $cmid Course module id.
     * @param int|null $chapterid Book chapter id, or null for non-book modules.
     * @param string $lang Language code.
     * @param string $voice Voice name.
     * @param string $model Model id.
     * @return \stdClass|null The most recent matching asset row, or null.
     */
    public static function find_current(
        int $cmid,
        ?int $chapterid,
        string $lang,
        string $voice,
        string $model
    ): ?\stdClass {
        global $DB;
        $params = [
            'cmid'  => $cmid,
            'lang'  => $lang,
            'voice' => $voice,
            'model' => $model,
        ];
        if ($chapterid === null) {
            $chaptercond = 'chapterid IS NULL';
        } else {
            $chaptercond = 'chapterid = :chapterid';
            $params['chapterid'] = $chapterid;
        }
        $sql = "SELECT * FROM {local_aireader_asset}
                 WHERE cmid = :cmid
                   AND {$chaptercond}
                   AND lang = :lang
                   AND voice = :voice
                   AND model = :model
              ORDER BY timemodified DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Fetch an asset row by id.
     *
     * @param int $id Asset id.
     * @return \stdClass|null
     */
    public static function get_by_id(int $id): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_aireader_asset', ['id' => $id]);
        return $row ?: null;
    }

    /**
     * Ensure a row exists for this variant, either reusing the current one or
     * creating a fresh pending row when the hash changed.
     *
     * @param array $fields Keys: courseid, cmid, contextid, module, instanceid,
     *                      chapterid, lang, voice, model, sourcehash.
     * @return array Tuple [\stdClass $asset, bool $existed_with_matching_hash].
     */
    public static function ensure_row(array $fields): array {
        global $DB;

        $existing = self::find_current(
            $fields['cmid'],
            $fields['chapterid'],
            $fields['lang'],
            $fields['voice'],
            $fields['model']
        );

        $now = time();

        if ($existing && $existing->sourcehash === $fields['sourcehash']) {
            $DB->set_field('local_aireader_asset', 'lastrequested', $now, ['id' => $existing->id]);
            $existing->lastrequested = $now;
            return [$existing, true];
        }

        if ($existing) {
            $DB->set_field('local_aireader_asset', 'status', self::STATUS_STALE, ['id' => $existing->id]);
        }

        $row = (object)[
            'courseid'      => $fields['courseid'],
            'cmid'          => $fields['cmid'],
            'contextid'     => $fields['contextid'],
            'module'        => $fields['module'],
            'instanceid'    => $fields['instanceid'],
            'chapterid'     => $fields['chapterid'],
            'lang'          => $fields['lang'],
            'voice'         => $fields['voice'],
            'model'         => $fields['model'],
            'sourcehash'    => $fields['sourcehash'],
            'status'        => self::STATUS_PENDING,
            'fileid'        => null,
            'bytesize'      => null,
            'durationsecs'  => null,
            'lasterror'     => null,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastgenerated' => null,
            'lastrequested' => $now,
        ];
        $row->id = $DB->insert_record('local_aireader_asset', $row);
        return [$row, false];
    }

    /**
     * Update an asset's status and optional error string.
     *
     * @param int $id Asset id.
     * @param string $status One of the STATUS_* constants.
     * @param string|null $error Optional error message to record.
     */
    public static function update_status(int $id, string $status, ?string $error = null): void {
        global $DB;
        $update = (object)[
            'id'           => $id,
            'status'       => $status,
            'timemodified' => time(),
        ];
        if ($error !== null) {
            $update->lasterror = $error;
        }
        if ($status === self::STATUS_READY) {
            $update->lastgenerated = $update->timemodified;
            $update->lasterror = null;
        }
        $DB->update_record('local_aireader_asset', $update);
    }

    /**
     * Mark an asset as ready and record the stored file metadata.
     *
     * @param int $id Asset id.
     * @param int $fileid files.id of the stored mp3.
     * @param int $bytesize File size in bytes.
     * @param int|null $duration Duration in seconds, or null if unknown.
     */
    public static function record_generated(int $id, int $fileid, int $bytesize, ?int $duration): void {
        global $DB;
        $now = time();
        $DB->update_record('local_aireader_asset', (object)[
            'id'            => $id,
            'fileid'        => $fileid,
            'bytesize'      => $bytesize,
            'durationsecs'  => $duration,
            'status'        => self::STATUS_READY,
            'lasterror'     => null,
            'timemodified'  => $now,
            'lastgenerated' => $now,
        ]);
    }

    /**
     * Queue a generation ad hoc task for an asset id.
     *
     * @param int $assetid
     */
    public static function queue_generation(int $assetid): void {
        $task = new generate_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        task_manager::queue_adhoc_task($task, true);
    }

    /**
     * Mark all non-stale assets for a course module as stale.
     *
     * @param int $cmid
     */
    public static function mark_cm_stale(int $cmid): void {
        global $DB;
        $DB->set_field_select(
            'local_aireader_asset',
            'status',
            self::STATUS_STALE,
            'cmid = :cmid AND status <> :stale',
            ['cmid' => $cmid, 'stale' => self::STATUS_STALE]
        );
    }

    /**
     * Mark all non-stale assets for a chapter as stale.
     *
     * @param int $chapterid
     */
    public static function mark_chapter_stale(int $chapterid): void {
        global $DB;
        $DB->set_field_select(
            'local_aireader_asset',
            'status',
            self::STATUS_STALE,
            'chapterid = :chapterid AND status <> :stale',
            ['chapterid' => $chapterid, 'stale' => self::STATUS_STALE]
        );
    }

    /**
     * Queue a regeneration task for every asset attached to a course module.
     *
     * @param int $cmid
     */
    public static function queue_regeneration_for_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['cmid' => $cmid], '', 'id');
        foreach ($rows as $row) {
            self::queue_generation((int)$row->id);
        }
    }

    /**
     * Purge all assets attached to a course module.
     *
     * @param int $cmid
     */
    public static function purge_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['cmid' => $cmid]);
        foreach ($rows as $row) {
            self::purge_asset($row);
        }
    }

    /**
     * Purge all assets attached to a chapter.
     *
     * @param int $chapterid
     */
    public static function purge_chapter(int $chapterid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['chapterid' => $chapterid]);
        foreach ($rows as $row) {
            self::purge_asset($row);
        }
    }

    /**
     * Delete a single asset row and its stored file.
     *
     * @param \stdClass $asset
     */
    public static function purge_asset(\stdClass $asset): void {
        global $DB;
        $fs = get_file_storage();
        $fs->delete_area_files($asset->contextid, 'local_aireader', 'audio', $asset->id);
        $DB->delete_records('local_aireader_asset', ['id' => $asset->id]);
    }
}
