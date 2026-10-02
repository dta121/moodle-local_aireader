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
 * Durable, asynchronous S3 storage and cleanup for generated audio.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Moves existing audio without invoking synthesis or changing its cost records.
 *
 * Destinations are stored with each object. Deletion records deliberately outlive
 * assets so a failed S3 request or a settings change cannot lose cleanup work.
 *
 * @package local_aireader
 */
class s3_storage {
    /** @var string Keep generated audio in Moodle. */
    public const MODE_LOCAL = 'local';
    /** @var string Keep both a Moodle file and a private S3 object. */
    public const MODE_MIRROR = 'mirror';
    /** @var string Release the Moodle file after the S3 upload succeeds. */
    public const MODE_PRIMARY = 'primary';
    /** @var callable|null Offline client factory, available only to PHPUnit. */
    private static $clientfactory = null;

    /**
     * Return the configured storage mode, failing closed for unknown values.
     *
     * @return string
     */
    public static function mode(): string {
        $mode = (string)get_config('local_aireader', 'storage_mode');
        return in_array($mode, [self::MODE_MIRROR, self::MODE_PRIMARY], true) ? $mode : self::MODE_LOCAL;
    }

    /**
     * Validate a private AWS destination without making any requests.
     *
     * @return array Bucket, region and a site-specific prefix.
     */
    public static function destination(): array {
        global $CFG;
        $bucket = trim((string)get_config('local_aireader', 's3_bucket'));
        $region = get_config('local_aireader', 's3_region');
        $prefix = get_config('local_aireader', 's3_prefix');
        $region = trim($region === false ? 'us-east-1' : (string)$region);
        $prefix = trim($prefix === false ? 'moodle-aireader' : (string)$prefix, '/');
        // Lowercase keys avoid collisions under case-insensitive database collations.
        if (
            !preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', $bucket)
            || str_contains($bucket, '..') || filter_var($bucket, FILTER_VALIDATE_IP)
            || !preg_match('/\A[a-z]{2}(?:-[a-z]+)+-\d+\z/', $region)
            || !preg_match('/\A[a-z0-9\/_-]{0,100}\z/', $prefix)
        ) {
            throw new \moodle_exception('error_s3_config', 'local_aireader');
        }
        $site = substr(hash('sha256', (string)($CFG->siteidentifier ?? $CFG->wwwroot)), 0, 24);
        return ['bucket' => $bucket, 'region' => $region, 'prefix' => ($prefix === '' ? '' : $prefix . '/') . $site . '/'];
    }

    /**
     * Resolve the committed remote copy, even if new S3 uploads are disabled.
     *
     * @param \stdClass $asset Asset record.
     * @return \stdClass|null
     */
    public static function get_remote(\stdClass $asset): ?\stdClass {
        global $DB;
        // Reads can hold an asset snapshot from before an offload or migration.
        // Resolve its current pointer so removing the local file cannot strand them.
        return $DB->get_record_sql("SELECT r.*
                                     FROM {local_aireader_s3} r
                                     JOIN {local_aireader_asset} a ON a.s3objectid = r.id AND a.id = r.assetid
                                    WHERE a.id = :assetid AND r.status = :status", [
            'assetid' => $asset->id, 'status' => 'ready',
        ]) ?: null;
    }

    /**
     * Create a transport using the server's IAM role or default AWS credentials.
     *
     * @param string $region Persisted AWS region.
     * @return s3_client
     */
    public static function client(string $region): s3_client {
        return self::$clientfactory ? (self::$clientfactory)($region) : new s3_client($region);
    }

    /**
     * Inject an offline transport in tests; never available in a web request.
     *
     * @param callable|null $factory Test factory or null to reset.
     */
    public static function set_client_factory(?callable $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('S3 client substitution is only available in PHPUnit.');
        }
        self::$clientfactory = $factory;
    }

    /**
     * Serialize generation, offloading and deletion of one asset.
     *
     * @param int $assetid Asset id.
     * @param int $timeout Seconds to wait; cron transfers skip busy assets.
     * @return \core\lock\lock|false
     */
    public static function lock(int $assetid, int $timeout = 0) {
        return \core\lock\lock_config::get_lock_factory('local_aireader_storage')
            ->get_lock('asset-' . $assetid, $timeout);
    }

    /**
     * Retire obsolete copies inside the caller's asset update/delete transaction.
     *
     * @param int $assetid Asset whose copies are obsolete.
     * @param int $keepid Current verified copy to retain, or zero for all copies.
     */
    public static function retire(int $assetid, int $keepid = 0): void {
        global $DB;
        $DB->execute("UPDATE {local_aireader_s3}
                         SET status = :status, retryafter = 0, timemodified = :now
                       WHERE assetid = :assetid AND id <> :keepid", [
            'status' => 'delete', 'now' => time(), 'assetid' => $assetid, 'keepid' => $keepid,
        ]);
    }

    /**
     * Find a bounded batch needing upload, destination migration or local restoration.
     *
     * @param int $limit Batch size.
     * @return \stdClass[] Assets.
     */
    public static function candidates(int $limit = 25): array {
        global $DB;
        if (self::mode() === self::MODE_LOCAL || $limit < 1) {
            return [];
        }
        [$where, $params] = self::candidate_where(false);
        return $DB->get_records_sql("SELECT a.*
                                      FROM {local_aireader_asset} a
                                 LEFT JOIN {local_aireader_s3} r ON r.id = a.s3objectid
                                     WHERE {$where}
                                  ORDER BY a.id", $params, 0, $limit);
    }

    /**
     * Count assets the current mode still has to transfer.
     *
     * @param bool $includecooling Also count assets waiting out a retry cooldown.
     * @return int
     */
    public static function count_candidates(bool $includecooling = true): int {
        global $DB;
        if (self::mode() === self::MODE_LOCAL) {
            return 0;
        }
        [$where, $params] = self::candidate_where($includecooling);
        return $DB->count_records_sql("SELECT COUNT(1)
                                         FROM {local_aireader_asset} a
                                    LEFT JOIN {local_aireader_s3} r ON r.id = a.s3objectid
                                        WHERE {$where}", $params);
    }

    /**
     * Shared filter for {@see candidates()} and {@see count_candidates()}.
     *
     * @param bool $includecooling Whether to ignore per-asset retry cooldowns.
     * @return array SQL condition and its parameters.
     */
    private static function candidate_where(bool $includecooling): array {
        global $DB;
        $destination = self::destination();
        $localcondition = self::mode() === self::MODE_PRIMARY ? 'a.fileid IS NOT NULL' : 'a.fileid IS NULL';
        $prefixcondition = $DB->sql_like('r.objectkey', ':prefix', true, true, true);
        $cooling = $includecooling ? '' : 'AND (a.s3retryafter IS NULL OR a.s3retryafter <= :now)';
        return ["a.status = :ready {$cooling}
                 AND (r.id IS NULL OR r.status <> :remoteready
                      OR r.bucket <> :bucket OR r.region <> :region
                      OR {$prefixcondition} OR {$localcondition})", [
            'ready' => asset_manager::STATUS_READY, 'now' => time(), 'remoteready' => 'ready',
            'bucket' => $destination['bucket'], 'region' => $destination['region'],
            'prefix' => $DB->sql_like_escape($destination['prefix']) . '%',
        ]];
    }

    /**
     * Synchronize an existing asset; an upload failure can never re-run synthesis.
     *
     * @param int $assetid Asset id.
     * @return bool True when synchronized, false when busy or not eligible.
     */
    public static function sync_asset(int $assetid): bool {
        global $DB;
        $mode = self::mode();
        if ($mode === self::MODE_LOCAL) {
            return false;
        }
        $destination = self::destination();
        $lock = self::lock($assetid);
        if (!$lock) {
            return false;
        }
        $object = null;
        try {
            $asset = asset_manager::get_by_id($assetid);
            if (!$asset || $asset->status !== asset_manager::STATUS_READY) {
                return false;
            }
            $remote = self::get_remote($asset);
            $file = storage::get_local_file($asset);
            if (!$file && $remote) {
                $key = self::object_key($destination, $assetid, $remote->contenthash);
                if ($mode === self::MODE_PRIMARY && self::matches($remote, $destination, $key)) {
                    $DB->update_record('local_aireader_asset', (object)[
                        'id' => $assetid, 'fileid' => null, 's3retryafter' => null, 's3lasterror' => null,
                    ]);
                    return true;
                }
                // Restore through the File API, without recording another paid generation.
                $path = make_request_directory() . '/audio.mp3';
                try {
                    storage::copy_audio_to_path($asset, $path);
                    $file = get_file_storage()->create_file_from_pathname([
                        'contextid' => $asset->contextid, 'component' => storage::COMPONENT,
                        'filearea' => storage::FILEAREA, 'itemid' => $assetid, 'filepath' => '/',
                        'filename' => 'asset-' . $assetid . '.mp3', 'mimetype' => 'audio/mpeg',
                    ], $path);
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                $DB->set_field('local_aireader_asset', 'fileid', $file->get_id(), ['id' => $assetid]);
            }
            if (!$file) {
                throw new \file_exception('storedfilecannotread');
            }
            // Also repair metadata if a previous process stopped after creating the file.
            $DB->set_field('local_aireader_asset', 'fileid', $file->get_id(), ['id' => $assetid]);
            $key = self::object_key($destination, $assetid, $file->get_contenthash());
            // A mirror's catalog entry is not proof its object still exists.
            // Re-PUT before every local eviction, with S3 validating the checksum.
            if (!$remote || !self::matches($remote, $destination, $key) || $mode === self::MODE_PRIMARY) {
                // Journal the exact destination before the request: even an ambiguous
                // network failure leaves enough information to retry or remove it.
                $object = $DB->get_record('local_aireader_s3', ['bucket' => $destination['bucket'], 'objectkey' => $key]);
                if (!$object) {
                    $object = (object)[
                        'assetid' => $assetid, 'bucket' => $destination['bucket'], 'region' => $destination['region'],
                        'objectkey' => $key, 'contenthash' => $file->get_contenthash(), 'filesize' => $file->get_filesize(),
                        'status' => 'pending', 'failcount' => 0, 'retryafter' => 0,
                        'timecreated' => time(), 'timemodified' => time(),
                    ];
                    $object->id = $DB->insert_record('local_aireader_s3', $object);
                }
                // A failed first upload may have recorded an incorrectly configured
                // region. Commit its correction only after the new request succeeds.
                $object->region = $destination['region'];
                $object->contenthash = $file->get_contenthash();
                $object->filesize = $file->get_filesize();
                self::client($destination['region'])->put_file($destination['bucket'], $key, $file);
                $transaction = $DB->start_delegated_transaction();
                try {
                    $object->status = 'ready';
                    $object->retryafter = 0;
                    $object->failcount = 0;
                    $object->lasterror = null;
                    $object->timemodified = time();
                    $DB->update_record('local_aireader_s3', $object);
                    $DB->update_record('local_aireader_asset', (object)[
                        'id' => $assetid, 's3objectid' => $object->id, 's3retryafter' => null, 's3lasterror' => null,
                    ]);
                    self::retire($assetid, (int)$object->id);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            }
            if ($mode === self::MODE_PRIMARY) {
                // Delete only the file we uploaded. Moodle owns deduplication and
                // trash retention; never unlink its contenthash path directly.
                $file->delete();
                $DB->set_field('local_aireader_asset', 'fileid', null, ['id' => $assetid]);
            }
            $DB->update_record('local_aireader_asset', (object)[
                'id' => $assetid, 's3retryafter' => null, 's3lasterror' => null,
            ]);
            return true;
        } catch (\Throwable $e) {
            $message = self::safe_error($e);
            $failcount = $object ? (int)$object->failcount + 1 : 1;
            $retryafter = time() + min(DAYSECS, 300 * (2 ** min(8, $failcount - 1)));
            if ($object) {
                $DB->update_record('local_aireader_s3', (object)[
                    'id' => $object->id, 'failcount' => $failcount, 'retryafter' => $retryafter,
                    'lasterror' => $message, 'timemodified' => time(),
                ]);
            }
            $DB->update_record('local_aireader_asset', (object)[
                'id' => $assetid, 's3retryafter' => $retryafter, 's3lasterror' => $message,
            ]);
            throw new \moodle_exception('s3transfererror', 'local_aireader', '', $message);
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete obsolete remote copies, retaining failed deletions for another run.
     *
     * @param int $limit Maximum objects per run.
     * @return int Number of objects removed.
     */
    public static function cleanup(int $limit = 25): int {
        global $DB;
        $objects = $DB->get_records_select('local_aireader_s3', 'status = :status AND retryafter <= :now', [
            'status' => 'delete', 'now' => time(),
        ], 'id', '*', 0, max(1, $limit));
        $deleted = 0;
        foreach ($objects as $object) {
            $lock = self::lock((int)$object->assetid);
            if (!$lock) {
                continue;
            }
            try {
                $current = $DB->get_record('local_aireader_s3', ['id' => $object->id, 'status' => 'delete']);
                if (!$current || $DB->record_exists('local_aireader_asset', ['s3objectid' => $object->id])) {
                    continue;
                }
                $object = $current;
                self::client($object->region)->delete_file($object->bucket, $object->objectkey);
                $DB->delete_records('local_aireader_s3', ['id' => $object->id]);
                $deleted++;
            } catch (\Throwable $e) {
                $failcount = (int)$object->failcount + 1;
                $DB->update_record('local_aireader_s3', (object)[
                    'id' => $object->id, 'failcount' => $failcount,
                    'retryafter' => time() + min(DAYSECS, 300 * (2 ** min(8, $failcount - 1))),
                    'lasterror' => self::safe_error($e), 'timemodified' => time(),
                ]);
                mtrace('local_aireader: S3 cleanup for object ' . $object->id . ' will retry: ' . self::safe_error($e));
            } finally {
                $lock->release();
            }
        }
        return $deleted;
    }

    /**
     * Count where ready audio currently lives, without contacting S3.
     *
     * @return array{local:int,mirrored:int,remote:int,remaining:int,cooling:int,pendingdelete:int,failed:int,bytes:int}
     */
    public static function summary(): array {
        global $DB;
        $ready = ['ready' => asset_manager::STATUS_READY];
        $count = fn(string $where, array $params = []) => $DB->count_records_select(
            'local_aireader_asset',
            'status = :ready AND ' . $where,
            $ready + $params
        );
        try {
            $remaining = self::count_candidates();
        } catch (\moodle_exception $e) {
            // Misconfigured destination: nothing can be transferred until it is fixed.
            $remaining = 0;
        }
        return [
            'local' => $count('fileid IS NOT NULL AND s3objectid IS NULL'),
            'mirrored' => $count('fileid IS NOT NULL AND s3objectid IS NOT NULL'),
            'remote' => $count('fileid IS NULL AND s3objectid IS NOT NULL'),
            'remaining' => $remaining,
            'cooling' => $count('s3retryafter > :now', ['now' => time()]),
            'pendingdelete' => $DB->count_records('local_aireader_s3', ['status' => 'delete']),
            'failed' => $count('s3lasterror IS NOT NULL'),
            'bytes' => (int)$DB->get_field_sql(
                "SELECT COALESCE(SUM(bytesize), 0) FROM {local_aireader_asset} WHERE status = :ready AND fileid IS NOT NULL",
                $ready
            ),
        ];
    }

    /**
     * Prove the server's credentials can write, read and delete under the prefix.
     *
     * Uses a throwaway object, so it never touches audio and leaves nothing behind
     * unless the final delete is denied (which the result then reports).
     *
     * @return string Object key that was exercised.
     * @throws \moodle_exception With the failing operation and safe AWS error code.
     */
    public static function test_connection(): string {
        $destination = self::destination();
        $key = $destination['prefix'] . 'connection-test/' . random_string(16) . '.txt';
        $content = 'local_aireader S3 connection test ' . time();
        $client = self::client($destination['region']);
        $client->put_content($destination['bucket'], $key, $content);
        $path = make_request_directory() . '/connection-test.txt';
        try {
            $client->download_file($destination['bucket'], $key, $path);
            if (!is_file($path) || file_get_contents($path) !== $content) {
                throw new \moodle_exception('error_s3_integrity', 'local_aireader');
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            $client->delete_file($destination['bucket'], $key);
        }
        return $key;
    }

    /**
     * Switch to S3 primary and offload every existing ready asset in the background.
     *
     * Clears per-asset retry cooldowns so earlier failures are attempted again
     * straight away, then queues {@see \local_aireader\task\migrate_s3}, which
     * keeps re-queueing itself until nothing is left to move. Local files are
     * only removed after their upload succeeds, exactly as in normal primary mode.
     *
     * @return int Number of assets that will be migrated.
     */
    public static function start_migration(): int {
        global $DB;
        self::destination();
        if (self::mode() !== self::MODE_PRIMARY) {
            set_config('storage_mode', self::MODE_PRIMARY, 'local_aireader');
        }
        $DB->set_field_select('local_aireader_asset', 's3retryafter', null, 's3retryafter IS NOT NULL');
        $DB->set_field_select('local_aireader_s3', 'retryafter', 0, 'retryafter > 0');
        $remaining = self::count_candidates();
        set_config('s3_migration_started', time(), 'local_aireader');
        \local_aireader\task\migrate_s3::queue();
        return $remaining;
    }

    /**
     * Name immutable audio bytes in a namespace unique to this Moodle site.
     *
     * @param array $destination Validated destination.
     * @param int $assetid Asset id.
     * @param string $hash Moodle content hash.
     * @return string
     */
    private static function object_key(array $destination, int $assetid, string $hash): string {
        return $destination['prefix'] . 'asset-' . $assetid . '/' . $hash . '.mp3';
    }

    /**
     * Whether an existing verified object already meets the chosen destination.
     *
     * @param \stdClass $remote Persisted object.
     * @param array $destination Validated destination.
     * @param string $key Expected object key.
     * @return bool
     */
    private static function matches(\stdClass $remote, array $destination, string $key): bool {
        return $remote->bucket === $destination['bucket'] && $remote->region === $destination['region']
            && $remote->objectkey === $key && $remote->status === 'ready';
    }

    /**
     * Keep credentials, signed URLs and arbitrary SDK debug data out of logs.
     *
     * @param \Throwable $error Failure.
     * @return string Safe description.
     */
    private static function safe_error(\Throwable $error): string {
        if ($error instanceof \moodle_exception && $error->module === 'local_aireader') {
            return $error->getMessage();
        }
        return 'Audio storage operation failed (' . get_class($error) . ').';
    }
}
