<?php
namespace local_aireader\manager;

use core\task\manager as task_manager;
use local_aireader\task\generate_audio;

defined('MOODLE_INTERNAL') || die();

/**
 * Lifecycle for local_aireader_asset rows and the files they own.
 */
class asset_manager {

    public const STATUS_PENDING    = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY      = 'ready';
    public const STATUS_ERROR      = 'error';
    public const STATUS_STALE      = 'stale';

    /**
     * Compute the canonical source hash for the (cm, chapter, voice, model, lang) variant.
     *
     * @param string $module    Module name.
     * @param int    $cmid      Course module id.
     * @param int|null $chapterid Book chapter id, or null.
     * @param string $lang      Language code.
     * @param string $voice     Voice name.
     * @param string $model     Model id.
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
     * @param int    $cmid      Course module id.
     * @param int|null $chapterid Book chapter id, or null for non-book modules.
     * @param string $lang      Language code.
     * @param string $voice     Voice name.
     * @param string $model     Model id.
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
            'cmid'      => $cmid,
            'chapterid' => $chapterid,
            'lang'      => $lang,
            'voice'     => $voice,
            'model'     => $model,
        ];
        $sql = "SELECT * FROM {local_aireader_asset}
                 WHERE cmid = :cmid
                   AND (chapterid = :chapterid OR (chapterid IS NULL AND :chapterid IS NULL))
                   AND lang = :lang
                   AND voice = :voice
                   AND model = :model
              ORDER BY timemodified DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, 1);
        return $rows ? reset($rows) : null;
    }

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
            // Touch lastrequested so we can prune cold assets later.
            $DB->set_field('local_aireader_asset', 'lastrequested', $now, ['id' => $existing->id]);
            $existing->lastrequested = $now;
            return [$existing, true];
        }

        if ($existing) {
            // Hash changed: mark the prior row stale; we will produce a new asset row.
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

    public static function queue_generation(int $assetid): void {
        $task = new generate_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        task_manager::queue_adhoc_task($task, true);
    }

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

    public static function queue_regeneration_for_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['cmid' => $cmid], '', 'id');
        foreach ($rows as $row) {
            self::queue_generation((int)$row->id);
        }
    }

    public static function purge_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['cmid' => $cmid]);
        foreach ($rows as $row) {
            self::purge_asset($row);
        }
    }

    public static function purge_chapter(int $chapterid): void {
        global $DB;
        $rows = $DB->get_records('local_aireader_asset', ['chapterid' => $chapterid]);
        foreach ($rows as $row) {
            self::purge_asset($row);
        }
    }

    public static function purge_asset(\stdClass $asset): void {
        global $DB;
        $fs = get_file_storage();
        $fs->delete_area_files($asset->contextid, 'local_aireader', 'audio', $asset->id);
        $DB->delete_records('local_aireader_asset', ['id' => $asset->id]);
    }
}
