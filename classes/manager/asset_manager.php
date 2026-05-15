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
     * For every enabled non-default language, ensure a pending asset row exists
     * for the given cm (or for each visible chapter of a book) and queue
     * generation. Only meaningful when `eager_languages_on_save` is on.
     *
     * @param int $cmid Course module id.
     */
    public static function queue_eager_language_generation_for_cm(int $cmid): void {
        global $DB, $CFG;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,instance,module');
        if (!$cm) {
            return;
        }
        $modnamerec = $DB->get_record('modules', ['id' => $cm->module], 'name');
        if (!$modnamerec) {
            return;
        }
        $modname = (string)$modnamerec->name;
        if (!in_array($modname, ['page', 'book'], true)) {
            return;
        }

        $enabled = self::enabled_languages();
        if (count($enabled) <= 1) {
            return;
        }
        $sourcelang = (string)($CFG->lang ?? 'en');
        $voice = (string)(get_config('local_aireader', 'voice') ?: 'marin');
        $model = (string)(get_config('local_aireader', 'model') ?: 'gpt-4o-mini-tts');
        $context = \context_module::instance($cmid);

        // Determine the set of (cmid, chapterid) tuples to seed.
        $tuples = [];
        if ($modname === 'page') {
            $tuples[] = [$cmid, 0];
        } else {
            $chapters = $DB->get_records('book_chapters', ['bookid' => $cm->instance, 'hidden' => 0], 'pagenum ASC', 'id');
            foreach ($chapters as $ch) {
                $tuples[] = [$cmid, (int)$ch->id];
            }
        }

        foreach ($tuples as [$thiscmid, $chapterid]) {
            try {
                $extracted = \local_aireader\manager\content_extractor::extract(
                    $modname,
                    $thiscmid,
                    $chapterid > 0 ? $chapterid : null
                );
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($enabled as $lang) {
                if (\local_aireader\manager\translation_manager::is_same_language($sourcelang, $lang)) {
                    // Source-language row is already handled by queue_regeneration_for_cm.
                    continue;
                }
                $hash = self::compute_hash(
                    $modname,
                    $thiscmid,
                    $chapterid > 0 ? $chapterid : null,
                    $lang,
                    $voice,
                    $model,
                    $extracted['text']
                );
                [$asset, $matched] = self::ensure_row([
                    'courseid'   => (int)$cm->course,
                    'cmid'       => $thiscmid,
                    'contextid'  => (int)$context->id,
                    'module'     => $modname,
                    'instanceid' => (int)$cm->instance,
                    'chapterid'  => $chapterid > 0 ? $chapterid : null,
                    'lang'       => $lang,
                    'voice'      => $voice,
                    'model'      => $model,
                    'sourcehash' => $hash,
                ]);
                if (!$matched || $asset->status !== self::STATUS_READY) {
                    self::queue_generation((int)$asset->id);
                }
            }
        }
    }

    /**
     * Return the trimmed, deduplicated list of enabled languages from settings.
     *
     * @return string[]
     */
    public static function enabled_languages(): array {
        $raw = (string)(get_config('local_aireader', 'enabled_languages') ?: 'en');
        $codes = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean = [];
        foreach ($codes as $c) {
            $c = trim($c);
            if ($c !== '' && !in_array($c, $clean, true)) {
                $clean[] = $c;
            }
        }
        return $clean ?: ['en'];
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
        position_manager::purge_for_asset((int)$asset->id);
        segment_manager::purge_for_asset((int)$asset->id);
        $DB->delete_records('local_aireader_asset', ['id' => $asset->id]);
    }

    /**
     * Purge stale asset rows (and their stored mp3s) older than the given age.
     *
     * Stale rows are produced when source content changes and a fresh asset row
     * is created in its place. They sit around so the audit trail is preserved
     * and so an in-flight regeneration task doesn't suddenly find its row gone,
     * but they are otherwise unreferenced and safe to remove once cold.
     *
     * @param int $olderthanseconds Maximum age in seconds (rows with timemodified
     *                               more than this many seconds in the past are
     *                               eligible for deletion).
     * @param int $batchlimit Soft cap on how many rows to delete in one run, to
     *                       avoid long-running cron jobs on backlogged sites.
     *                       0 means "no cap".
     * @return int Number of asset rows purged.
     */
    public static function purge_stale_older_than(int $olderthanseconds, int $batchlimit = 500): int {
        global $DB;
        if ($olderthanseconds <= 0) {
            return 0;
        }
        $cutoff = time() - $olderthanseconds;
        $rows = $DB->get_records_select(
            'local_aireader_asset',
            'status = :status AND timemodified < :cutoff',
            ['status' => self::STATUS_STALE, 'cutoff' => $cutoff],
            'timemodified ASC',
            '*',
            0,
            $batchlimit > 0 ? $batchlimit : 0
        );
        $purged = 0;
        foreach ($rows as $row) {
            self::purge_asset($row);
            $purged++;
        }
        return $purged;
    }
}
