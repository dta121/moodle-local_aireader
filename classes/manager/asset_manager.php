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

    /** Default hard cap (characters) on cleaned narration text per asset. */
    public const DEFAULT_MAX_NARRATION_CHARS = 50000;

    /**
     * Resolve the configured hard cap on cleaned narration text length.
     *
     * @return int Max characters allowed before generation is refused. 0 disables.
     */
    public static function max_narration_chars(): int {
        $value = (int)get_config('local_aireader', 'max_narration_chars');
        if ($value <= 0) {
            return self::DEFAULT_MAX_NARRATION_CHARS;
        }
        return $value;
    }

    /**
     * Throw a capability exception if the chapter is hidden and the caller
     * lacks `mod/book:viewhiddenchapters` on the supplied module context.
     *
     * Centralized so `get_status`, `request_regen`, and the pluginfile handler
     * share one gate. Book chapters carry their own visibility flag separate
     * from cm-level uservisible, so cm-level access alone is not enough.
     *
     * Accepts either a raw cm record (`stdClass` from
     * `get_coursemodule_from_id`) or a `cm_info` (as returned by
     * `get_course_and_cm_from_cmid`); both expose the `instance` property
     * we need to scope the chapter lookup to the right book.
     *
     * @param \cm_info|\stdClass $cm Course module record or cm_info.
     * @param int $chapterid Book chapter id.
     * @param \context_module $context Module context for the capability check.
     * @throws \required_capability_exception When the chapter is hidden and
     *                                        the caller lacks the override cap.
     * @throws \dml_exception When the chapter does not belong to the cm's book.
     */
    public static function assert_chapter_visible(
        \cm_info|\stdClass $cm,
        int $chapterid,
        \context_module $context
    ): void {
        global $DB;
        if ($chapterid <= 0) {
            return;
        }
        $chapter = $DB->get_record(
            'book_chapters',
            ['id' => $chapterid, 'bookid' => $cm->instance],
            'id, hidden',
            MUST_EXIST
        );
        if (!empty($chapter->hidden) && !has_capability('mod/book:viewhiddenchapters', $context)) {
            throw new \required_capability_exception(
                $context,
                'mod/book:viewhiddenchapters',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Re-check visibility for an already-created asset row.
     *
     * Asset-id web service calls do not receive the source chapter id directly,
     * so they must derive it from the asset before returning derived content
     * such as transcripts or accepting playback positions.
     *
     * @param \stdClass $asset Asset row.
     * @param \context_module $context Module context already validated by the caller.
     * @throws \invalid_parameter_exception When the asset no longer belongs to
     *                                      the supplied context.
     * @throws \required_capability_exception When the asset is a hidden Book
     *                                        chapter and the caller cannot view it.
     */
    public static function assert_asset_visible(\stdClass $asset, \context_module $context): void {
        $module = (string)($asset->module ?? '');
        if (!in_array($module, ['page', 'book'], true)) {
            throw new \invalid_parameter_exception('Unsupported asset module');
        }

        $sourcecm = get_coursemodule_from_id($module, (int)$asset->cmid, (int)$asset->courseid, false, MUST_EXIST);
        if ((int)$sourcecm->id !== (int)$context->instanceid || (int)$asset->contextid !== (int)$context->id) {
            throw new \invalid_parameter_exception('Asset context mismatch');
        }

        if ($module === 'book' && !empty($asset->chapterid)) {
            self::assert_chapter_visible($sourcecm, (int)$asset->chapterid, $context);
        } else if ($module !== 'book' && !empty($asset->chapterid)) {
            throw new \invalid_parameter_exception('Asset chapter mismatch');
        }
    }

    /**
     * Whether narration is actually available for a scope, mirroring the gates
     * the player-injection hook applies before showing any UI.
     *
     * The web services and the pluginfile handler must enforce this server-side:
     * relying on the player simply not being rendered would let any user with
     * the listen capability call the AJAX endpoints directly and queue paid
     * OpenAI generation (or stream audio/transcripts) for scopes where narration
     * is switched off.
     *
     * Gate order matches {@see \local_aireader\hook_callbacks::inject_player()}:
     * the plugin master switch and the per-module switch turn narration off for
     * everyone (including managers); a per-activity/chapter override only turns
     * it off for users without `local/aireader:manage`, who need continued
     * access to re-enable and verify narration.
     *
     * @param string $module Module name (page or book).
     * @param int $cmid Course module id.
     * @param int $chapterid Book chapter id, or 0 for activity scope.
     * @param \context_module $context Module context for the manage check.
     * @return bool
     */
    public static function is_narration_available(
        string $module,
        int $cmid,
        int $chapterid,
        \context_module $context
    ): bool {
        if (!get_config('local_aireader', 'enabled')) {
            return false;
        }
        $globalkey = $module === 'book' ? 'enable_book' : 'enable_page';
        if (!get_config('local_aireader', $globalkey)) {
            return false;
        }
        if (override_manager::is_enabled($cmid, $chapterid, $module)) {
            return true;
        }
        return has_capability('local/aireader:manage', $context);
    }

    /**
     * Throw when narration is not available for the scope.
     *
     * @param string $module Module name (page or book).
     * @param int $cmid Course module id.
     * @param int $chapterid Book chapter id, or 0 for activity scope.
     * @param \context_module $context Module context for the manage check.
     * @throws \moodle_exception When narration is disabled at this scope.
     */
    public static function assert_narration_available(
        string $module,
        int $cmid,
        int $chapterid,
        \context_module $context
    ): void {
        if (!self::is_narration_available($module, $cmid, $chapterid, $context)) {
            throw new \moodle_exception('error_narration_disabled', 'local_aireader');
        }
    }

    /**
     * Compute the canonical source hash for the (cm, chapter, voice, model, lang) variant.
     *
     * @param string $module Module name.
     * @param int $cmid Course module id.
     * @param int|null $chapterid Book chapter id, or null/0 for no chapter.
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
            $chapterid === null || $chapterid <= 0 ? '' : (string)$chapterid,
            $lang,
            $voice,
            $model,
            $cleantext,
        ]);
        return hash('sha256', $payload);
    }

    /**
     * Normalise the nullable historical chapter id representation.
     *
     * Fresh rows store 0 for Page/no-chapter assets so the unique variant index
     * is portable and actually prevents duplicate Page assets.
     *
     * @param int|null $chapterid Raw chapter id.
     * @return int Positive Book chapter id, or 0 for Page/no chapter.
     */
    private static function normalise_chapterid(?int $chapterid): int {
        return $chapterid !== null && $chapterid > 0 ? $chapterid : 0;
    }

    /**
     * Look up the newest asset row for the requested variant.
     *
     * @param int $cmid Course module id.
     * @param int|null $chapterid Book chapter id, or null/0 for non-book modules.
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
        $chapterid = self::normalise_chapterid($chapterid);
        $params = [
            'cmid'      => $cmid,
            'chapterid' => $chapterid,
            'lang'      => $lang,
            'voice'     => $voice,
            'model'     => $model,
        ];
        $sql = "SELECT * FROM {local_aireader_asset}
                 WHERE cmid = :cmid
                   AND chapterid = :chapterid
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
        $chapterid = self::normalise_chapterid($fields['chapterid'] ?? null);
        $lockkey = sha1(implode('|', [
            (string)$fields['cmid'],
            (string)$chapterid,
            (string)$fields['lang'],
            (string)$fields['voice'],
            (string)$fields['model'],
        ]));
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_aireader_asset');
        $lock = $lockfactory->get_lock($lockkey, 10);
        if (!$lock) {
            throw new \moodle_exception('error_asset_lock_timeout', 'local_aireader');
        }

        try {
            $existing = self::find_current(
                $fields['cmid'],
                $chapterid,
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
                'chapterid'     => $chapterid,
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
        } finally {
            $lock->release();
        }
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
     * @param int|null $inputchars Narration character count sent to the model,
     *                             or null if unknown. Basis for cost estimation.
     */
    public static function record_generated(
        int $id,
        int $fileid,
        int $bytesize,
        ?int $duration,
        ?int $inputchars = null
    ): void {
        global $DB;
        $now = time();
        $DB->update_record('local_aireader_asset', (object)[
            'id'            => $id,
            'fileid'        => $fileid,
            'bytesize'      => $bytesize,
            'durationsecs'  => $duration,
            'inputchars'    => $inputchars,
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

        $maxchars = self::max_narration_chars();
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
            // Skip eager pre-generation for over-cap content; the per-asset
            // task will surface a clean error on the source-language asset.
            if ($maxchars > 0 && mb_strlen($extracted['text']) > $maxchars) {
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
                    'chapterid'  => $chapterid,
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
     * Merges the checklist setting (`enabled_languages`, stored as a
     * comma-separated code list) with the free-text escape hatch
     * (`enabled_languages_extra`) that lets admins enable languages OpenAI
     * ships before the plugin's built-in checklist catches up. Codes are
     * normalised to Moodle's lowercase/underscore form so hand-typed variants
     * like "PT-BR" match the codes the player and translator use.
     *
     * @return string[]
     */
    public static function enabled_languages(): array {
        $raw = (string)(get_config('local_aireader', 'enabled_languages') ?: 'en');
        $extra = (string)get_config('local_aireader', 'enabled_languages_extra');
        $codes = preg_split('/[\s,;]+/', $raw . ',' . $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean = [];
        foreach ($codes as $c) {
            $c = strtolower(str_replace('-', '_', trim($c)));
            if ($c !== '' && !in_array($c, $clean, true)) {
                $clean[] = $c;
            }
        }
        return $clean ?: ['en'];
    }

    /**
     * The site's default TTS voice: used when the client requests none, and
     * always available to learners regardless of the enabled-voices checklist.
     *
     * @return string Lowercased voice id.
     */
    public static function default_voice(): string {
        return strtolower(trim((string)(get_config('local_aireader', 'voice') ?: 'marin')));
    }

    /**
     * Return the deduplicated list of voices learners may request.
     *
     * Mirrors {@see enabled_languages()}: merges the checklist setting
     * (`enabled_voices`, stored as a comma-separated id list) with the
     * free-text escape hatch (`enabled_voices_extra`) for voices OpenAI ships
     * before the plugin's built-in checklist catches up. The default voice is
     * always first — it is the initial selection and stays available even
     * when unticked.
     *
     * @return string[] Voice ids, default voice first.
     */
    public static function enabled_voices(): array {
        $raw = (string)get_config('local_aireader', 'enabled_voices');
        $extra = (string)get_config('local_aireader', 'enabled_voices_extra');
        $codes = preg_split('/[\s,;]+/', $raw . ',' . $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean = [self::default_voice()];
        foreach ($codes as $c) {
            $c = strtolower(trim($c));
            if ($c !== '' && !in_array($c, $clean, true)) {
                $clean[] = $c;
            }
        }
        return $clean;
    }

    /**
     * Purge all assets attached to a course module.
     *
     * @param int $cmid
     */
    public static function purge_cm(int $cmid): void {
        global $DB;
        self::purge_assets($DB->get_records('local_aireader_asset', ['cmid' => $cmid]));
    }

    /**
     * Purge all assets attached to a chapter.
     *
     * @param int $chapterid
     */
    public static function purge_chapter(int $chapterid): void {
        global $DB;
        self::purge_assets($DB->get_records('local_aireader_asset', ['chapterid' => $chapterid]));
    }

    /**
     * Delete a single asset row and its stored file.
     *
     * @param \stdClass $asset
     */
    public static function purge_asset(\stdClass $asset): void {
        self::purge_assets([$asset]);
    }

    /**
     * Delete a batch of asset rows, their stored files, and dependent data.
     *
     * Stored files must be deleted per (context, itemid) through the File API,
     * but the dependent tables are cleaned with one IN-list query each rather
     * than four queries per asset.
     *
     * @param \stdClass[] $assets Asset rows (id and contextid required).
     */
    private static function purge_assets(array $assets): void {
        global $DB;
        if (!$assets) {
            return;
        }
        $fs = get_file_storage();
        $ids = [];
        foreach ($assets as $asset) {
            $fs->delete_area_files($asset->contextid, 'local_aireader', 'audio', $asset->id);
            $ids[] = (int)$asset->id;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('local_aireader_position', "assetid {$insql}", $params);
        $DB->delete_records_select('local_aireader_listen', "assetid {$insql}", $params);
        $DB->delete_records_select('local_aireader_segment', "assetid {$insql}", $params);
        $DB->delete_records_select('local_aireader_asset', "id {$insql}", $params);
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
        self::purge_assets($rows);
        return count($rows);
    }
}
