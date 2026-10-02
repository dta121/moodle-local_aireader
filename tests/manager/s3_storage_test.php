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
 * Tests for durable audio offloading and remote object cleanup.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Verify the lifecycle against Moodle files and an in-memory S3 transport.
 *
 * @coversDefaultClass \local_aireader\manager\s3_storage
 */
final class s3_storage_test extends \advanced_testcase {
    /** @var array<string,string> Remote object bytes keyed by bucket and object key. */
    private array $objects = [];
    /** @var array Upload destinations in request order. */
    private array $uploads = [];
    /** @var array Download destinations in request order. */
    private array $downloads = [];
    /** @var array Delete destinations in request order. */
    private array $deletes = [];
    /** @var array AWS regions used to construct a client. */
    private array $regions = [];
    /** @var bool Simulate upload failures. */
    private bool $failupload = false;
    /** @var bool Simulate deletion failures. */
    private bool $faildelete = false;

    /**
     * Install a transport which never makes network requests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('s3_bucket', 'test-private-audio', 'local_aireader');
        set_config('s3_region', 'us-east-1', 'local_aireader');
        set_config('s3_prefix', 'narration', 'local_aireader');
        $client = $this->getMockBuilder(s3_client::class)->disableOriginalConstructor()->getMock();
        $client->method('put_file')->willReturnCallback(function (string $bucket, string $key, \stored_file $file): void {
            $this->uploads[] = [$bucket, $key];
            $this->assertTrue(storage::has_audio((object)[
                'id' => $file->get_itemid(), 'contextid' => $file->get_contextid(),
            ]));
            if ($this->failupload) {
                throw new \RuntimeException('secret-upload-token https://private.example/signed?credential=secret');
            }
            $this->objects[$bucket . '/' . $key] = $file->get_content();
        });
        $client->method('put_content')->willReturnCallback(function (string $bucket, string $key, string $content): void {
            $this->uploads[] = [$bucket, $key];
            if ($this->failupload) {
                throw new \moodle_exception('s3transfererror', 'local_aireader', '', 'PutObject: AccessDenied (HTTP 403)');
            }
            $this->objects[$bucket . '/' . $key] = $content;
        });
        $client->method('download_file')->willReturnCallback(function (string $bucket, string $key, string $path): void {
            $this->downloads[] = [$bucket, $key];
            if (!isset($this->objects[$bucket . '/' . $key])) {
                throw new \RuntimeException('Remote object is missing.');
            }
            file_put_contents($path, $this->objects[$bucket . '/' . $key]);
        });
        $client->method('delete_file')->willReturnCallback(function (string $bucket, string $key): void {
            $this->deletes[] = [$bucket, $key];
            if ($this->faildelete) {
                throw new \RuntimeException('secret-delete-token https://private.example/signed?credential=secret');
            }
            unset($this->objects[$bucket . '/' . $key]);
        });
        s3_storage::set_client_factory(function (string $region) use ($client): s3_client {
            $this->regions[] = $region;
            return $client;
        });
    }

    /**
     * Prevent test clients from leaking into a later test.
     */
    protected function tearDown(): void {
        s3_storage::set_client_factory(null);
        parent::tearDown();
    }

    /**
     * Existing sites default to local storage and make no S3 requests.
     *
     * @covers ::mode
     * @covers ::candidates
     * @covers ::sync_asset
     */
    public function test_default_local_mode_leaves_audio_in_moodle(): void {
        $asset = $this->create_asset();
        unset_config('storage_mode', 'local_aireader');

        $this->assertSame(s3_storage::MODE_LOCAL, s3_storage::mode());
        $this->assertSame([], s3_storage::candidates());
        $this->assertFalse(s3_storage::sync_asset((int)$asset->id));
        $this->assertNotNull(storage::get_local_file($asset));
        $this->assertNull(s3_storage::get_remote($asset));
        $this->assertSame([], $this->uploads);

        set_config('storage_mode', 'unrecognized', 'local_aireader');
        $this->assertSame(s3_storage::MODE_LOCAL, s3_storage::mode());
    }

    /**
     * Mirror mode keeps both copies and never uploads identical audio twice.
     *
     * @covers ::sync_asset
     * @covers ::get_remote
     * @covers ::candidates
     * @covers ::destination
     */
    public function test_mirror_is_idempotent_and_keeps_local_audio(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        $this->assertArrayHasKey($asset->id, s3_storage::candidates());

        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $asset = asset_manager::get_by_id((int)$asset->id);
        $remote = s3_storage::get_remote($asset);

        $this->assertNotNull($remote);
        $this->assertSame('ready', $remote->status);
        $this->assertSame('test-private-audio', $remote->bucket);
        $this->assertSame('us-east-1', $remote->region);
        $this->assertStringStartsWith(s3_storage::destination()['prefix'], $remote->objectkey);
        $this->assertSame(sha1('ID3 narration bytes'), $remote->contenthash);
        $this->assertSame('ID3 narration bytes', storage::get_local_file($asset)->get_content());
        $this->assertSame('ID3 narration bytes', $this->objects[$remote->bucket . '/' . $remote->objectkey]);
        $this->assertSame([], s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $this->assertCount(1, $this->uploads);
        $this->assertSame(1, $DB->count_records('local_aireader_s3'));
    }

    /**
     * Primary mode releases the local file only after saving a remote copy.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_primary_preserves_audio_metadata_and_cost(): void {
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $before = $this->create_asset();
        $cost = cost_calculator::estimate_usd($before->model, (int)$before->inputchars, (int)$before->lastgenerated);

        $this->assertTrue(s3_storage::sync_asset((int)$before->id));
        $after = asset_manager::get_by_id((int)$before->id);

        $this->assertNull($after->fileid);
        $this->assertNull(storage::get_local_file($after));
        $this->assertNotNull(s3_storage::get_remote($after));
        foreach (['status', 'bytesize', 'durationsecs', 'inputchars', 'lastgenerated', 'model'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field}, $field . ' must remain unchanged after offloading.');
        }
        $this->assertSame($cost, cost_calculator::estimate_usd(
            $after->model,
            (int)$after->inputchars,
            (int)$after->lastgenerated
        ));
        $this->assertSame([], s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$before->id));
        $this->assertCount(1, $this->uploads);
        $this->assertSame([], $this->downloads);
    }

    /**
     * Switching to primary must re-upload local bytes even when the catalog says ready.
     *
     * The bucket object may have been removed externally since mirror mode saved it.
     *
     * @covers ::sync_asset
     */
    public function test_primary_restores_a_missing_mirror_object_before_local_eviction(): void {
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $mirrored = asset_manager::get_by_id((int)$asset->id);
        $remote = s3_storage::get_remote($mirrored);
        unset($this->objects[$remote->bucket . '/' . $remote->objectkey]);
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');

        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $offloaded = asset_manager::get_by_id((int)$asset->id);

        $this->assertCount(2, $this->uploads);
        $this->assertSame('ID3 narration bytes', $this->objects[$remote->bucket . '/' . $remote->objectkey]);
        $this->assertSame($mirrored->s3objectid, $offloaded->s3objectid);
        $this->assertNull(storage::get_local_file($offloaded));
        $this->assertNull($offloaded->fileid);
        $this->assertSame($asset->lastgenerated, $offloaded->lastgenerated);
    }

    /**
     * A failed primary re-upload retains the local copy and committed remote pointer.
     *
     * @covers ::sync_asset
     */
    public function test_primary_reupload_failure_preserves_local_audio_and_ready_pointer(): void {
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $mirrored = asset_manager::get_by_id((int)$asset->id);
        $remote = s3_storage::get_remote($mirrored);
        unset($this->objects[$remote->bucket . '/' . $remote->objectkey]);
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $this->failupload = true;

        try {
            s3_storage::sync_asset((int)$asset->id);
            $this->fail('A failed re-upload must prevent local file eviction.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
        }
        $failed = asset_manager::get_by_id((int)$asset->id);

        $this->assertSame(asset_manager::STATUS_READY, $failed->status);
        $this->assertSame($mirrored->fileid, $failed->fileid);
        $this->assertSame($mirrored->s3objectid, $failed->s3objectid);
        $this->assertSame('ready', s3_storage::get_remote($failed)->status);
        $this->assertSame('ID3 narration bytes', storage::get_local_file($failed)->get_content());
        $this->assertGreaterThan(time(), (int)$failed->s3retryafter);
        $this->assertCount(2, $this->uploads);

        $this->failupload = false;
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $recovered = asset_manager::get_by_id((int)$asset->id);
        $this->assertSame($mirrored->s3objectid, $recovered->s3objectid);
        $this->assertSame('ID3 narration bytes', $this->objects[$remote->bucket . '/' . $remote->objectkey]);
        $this->assertNull(storage::get_local_file($recovered));
        $this->assertNull($recovered->s3retryafter);
    }

    /**
     * A failed upload retains playable audio, schedules a retry and avoids TTS.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_upload_failure_keeps_ready_local_audio_and_reuses_journal_on_retry(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $asset = $this->create_asset();
        $this->failupload = true;
        try {
            s3_storage::sync_asset((int)$asset->id);
            $this->fail('The upload failure must be reported.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
        }

        $failed = asset_manager::get_by_id((int)$asset->id);
        $journal = $DB->get_record('local_aireader_s3', ['assetid' => $asset->id], '*', MUST_EXIST);
        $this->assertSame(asset_manager::STATUS_READY, $failed->status);
        $this->assertNotNull(storage::get_local_file($failed));
        $this->assertNull($failed->s3objectid);
        $this->assertGreaterThan(time(), (int)$failed->s3retryafter);
        $this->assertStringNotContainsString('secret', $failed->s3lasterror);
        $this->assertSame('pending', $journal->status);
        $this->assertSame(1, (int)$journal->failcount);
        $this->assertSame([], s3_storage::candidates());

        $this->failupload = false;
        $DB->set_field('local_aireader_asset', 's3retryafter', 0, ['id' => $asset->id]);
        $this->assertArrayHasKey($asset->id, s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $recovered = asset_manager::get_by_id((int)$asset->id);
        $this->assertSame((int)$journal->id, (int)$recovered->s3objectid);
        $this->assertNull($recovered->s3retryafter);
        $this->assertNull($recovered->s3lasterror);
        $this->assertNull(storage::get_local_file($recovered));
        $this->assertSame($asset->lastgenerated, $recovered->lastgenerated);
        $this->assertSame(1, $DB->count_records('local_aireader_s3'));
    }

    /**
     * Correcting a bucket region after a failed upload updates the durable destination.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_retry_commits_corrected_region_for_an_existing_pending_object(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        $this->failupload = true;
        try {
            s3_storage::sync_asset((int)$asset->id);
            $this->fail('The initial upload must fail.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
        }
        $pending = $DB->get_record('local_aireader_s3', ['assetid' => $asset->id], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertSame('us-east-1', $pending->region);

        set_config('s3_region', 'us-west-2', 'local_aireader');
        $this->failupload = false;
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $remote = s3_storage::get_remote(asset_manager::get_by_id((int)$asset->id));

        $this->assertSame($pending->id, $remote->id);
        $this->assertSame($pending->bucket, $remote->bucket);
        $this->assertSame($pending->objectkey, $remote->objectkey);
        $this->assertSame('us-west-2', $remote->region);
        $this->assertSame('ready', $remote->status);
        $this->assertSame(['us-east-1', 'us-west-2'], $this->regions);
        $this->assertSame([], s3_storage::candidates());
        $this->assertSame(1, $DB->count_records('local_aireader_s3'));
    }

    /**
     * Switching back to mirror restores verified bytes without generating audio.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_mirror_restores_a_remote_only_asset(): void {
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $remoteonly = asset_manager::get_by_id((int)$asset->id);
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');

        $this->assertArrayHasKey($asset->id, s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $restored = asset_manager::get_by_id((int)$asset->id);

        $this->assertNotNull($restored->fileid);
        $this->assertSame('ID3 narration bytes', storage::get_local_file($restored)->get_content());
        $this->assertSame($remoteonly->s3objectid, $restored->s3objectid);
        $this->assertSame($asset->lastgenerated, $restored->lastgenerated);
        $this->assertSame($asset->inputchars, $restored->inputchars);
        $this->assertCount(1, $this->uploads);
        $this->assertCount(1, $this->downloads);
        $this->assertSame([], s3_storage::candidates());
    }

    /**
     * Destination migration retains the old remote object until the new upload succeeds.
     *
     * @covers ::sync_asset
     * @covers ::retire
     * @covers ::candidates
     */
    public function test_destination_migration_preserves_old_copy_until_upload_succeeds(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $asset = asset_manager::get_by_id((int)$asset->id);
        $oldremote = s3_storage::get_remote($asset);
        set_config('s3_bucket', 'replacement-private-audio', 'local_aireader');
        set_config('s3_region', 'us-west-2', 'local_aireader');
        set_config('s3_prefix', 'new-narration', 'local_aireader');
        $this->assertArrayHasKey($asset->id, s3_storage::candidates());

        $this->failupload = true;
        try {
            s3_storage::sync_asset((int)$asset->id);
            $this->fail('The replacement upload failure must be reported.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
        }
        $failed = asset_manager::get_by_id((int)$asset->id);
        $this->assertSame($oldremote->id, s3_storage::get_remote($failed)->id);
        $this->assertSame('ready', $DB->get_field('local_aireader_s3', 'status', ['id' => $oldremote->id]));
        $this->assertNotNull(storage::get_local_file($failed));
        $this->assertSame([], $this->deletes);

        $this->failupload = false;
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $migrated = asset_manager::get_by_id((int)$asset->id);
        $newremote = s3_storage::get_remote($migrated);
        $this->assertNotSame($oldremote->id, $newremote->id);
        $this->assertSame('replacement-private-audio', $newremote->bucket);
        $this->assertSame('us-west-2', $newremote->region);
        $this->assertSame('delete', $DB->get_field('local_aireader_s3', 'status', ['id' => $oldremote->id]));
        $this->assertNull(storage::get_local_file($migrated));
        $this->assertArrayHasKey($oldremote->bucket . '/' . $oldremote->objectkey, $this->objects);
        $this->assertSame(1, s3_storage::cleanup());
        $this->assertSame([[$oldremote->bucket, $oldremote->objectkey]], $this->deletes);
        $this->assertArrayHasKey($newremote->bucket . '/' . $newremote->objectkey, $this->objects);
    }

    /**
     * Regeneration clears the old remote pointer and keeps a deletion journal.
     *
     * @covers ::retire
     * @covers \local_aireader\manager\asset_manager::record_generated
     */
    public function test_regeneration_retires_old_remote_audio(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $asset = asset_manager::get_by_id((int)$asset->id);
        $oldremote = s3_storage::get_remote($asset);
        $newbytes = 'ID3 new narration content';
        $file = storage::store_mp3((int)$asset->id, (int)$asset->contextid, $newbytes);

        asset_manager::record_generated((int)$asset->id, $file->get_id(), strlen($newbytes), 99, 1200);
        $regenerated = asset_manager::get_by_id((int)$asset->id);

        $this->assertNull($regenerated->s3objectid);
        $this->assertNull(s3_storage::get_remote($regenerated));
        $this->assertSame($newbytes, storage::get_audio_content($regenerated));
        $this->assertSame('delete', $DB->get_field('local_aireader_s3', 'status', ['id' => $oldremote->id]));
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $freshremote = s3_storage::get_remote(asset_manager::get_by_id((int)$asset->id));
        $this->assertNotSame($oldremote->objectkey, $freshremote->objectkey);
        $this->assertSame(sha1($newbytes), $freshremote->contenthash);
    }

    /**
     * Purging an asset leaves durable cleanup which still works after settings change.
     *
     * @covers ::retire
     * @covers ::cleanup
     * @covers \local_aireader\manager\asset_manager::purge_asset
     */
    public function test_purge_retains_tombstone_and_retries_original_destination(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $asset = asset_manager::get_by_id((int)$asset->id);
        $remote = s3_storage::get_remote($asset);

        asset_manager::purge_asset($asset);
        $this->assertFalse($DB->record_exists('local_aireader_asset', ['id' => $asset->id]));
        $this->assertSame('delete', $DB->get_field('local_aireader_s3', 'status', ['id' => $remote->id]));
        set_config('storage_mode', s3_storage::MODE_LOCAL, 'local_aireader');
        set_config('s3_bucket', 'different-current-bucket', 'local_aireader');
        set_config('s3_region', 'eu-west-1', 'local_aireader');
        $this->faildelete = true;
        ob_start();
        try {
            $this->assertSame(0, s3_storage::cleanup());
        } finally {
            $output = ob_get_clean();
        }
        $pending = $DB->get_record('local_aireader_s3', ['id' => $remote->id], '*', MUST_EXIST);
        $this->assertSame('delete', $pending->status);
        $this->assertSame(1, (int)$pending->failcount);
        $this->assertGreaterThan(time(), (int)$pending->retryafter);
        $this->assertStringNotContainsString('secret', $pending->lasterror);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertSame(0, s3_storage::cleanup());
        $this->assertCount(1, $this->deletes);

        $this->faildelete = false;
        $DB->set_field('local_aireader_s3', 'retryafter', 0, ['id' => $remote->id]);
        $this->assertSame(1, s3_storage::cleanup());
        $this->assertFalse($DB->record_exists('local_aireader_s3', ['id' => $remote->id]));
        $this->assertSame([
            [$remote->bucket, $remote->objectkey], [$remote->bucket, $remote->objectkey],
        ], $this->deletes);
        $this->assertSame(['us-east-1', 'us-east-1', 'us-east-1'], $this->regions);
    }

    /**
     * A referenced object cannot be deleted even if its journal is marked obsolete.
     *
     * @covers ::cleanup
     */
    public function test_cleanup_does_not_delete_an_asset_current_copy(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $asset = asset_manager::get_by_id((int)$asset->id);
        $DB->set_field('local_aireader_s3', 'status', 'delete', ['id' => $asset->s3objectid]);

        $this->assertSame(0, s3_storage::cleanup());
        $this->assertSame([], $this->deletes);
        $this->assertTrue($DB->record_exists('local_aireader_s3', ['id' => $asset->s3objectid]));
    }

    /**
     * Cron selects eligible ready assets in bounded batches and honors retry delays.
     *
     * @covers ::candidates
     */
    public function test_candidates_exclude_completed_nonready_and_cooling_down_assets(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $complete = $this->create_asset();
        $coolingdown = $this->create_asset();
        $failed = $this->create_asset();
        $eligible = $this->create_asset();
        s3_storage::sync_asset((int)$complete->id);
        $DB->set_field('local_aireader_asset', 's3retryafter', time() + HOURSECS, ['id' => $coolingdown->id]);
        $DB->set_field('local_aireader_asset', 'status', asset_manager::STATUS_ERROR, ['id' => $failed->id]);

        $this->assertSame([(int)$eligible->id], array_keys(s3_storage::candidates()));
        $this->assertSame([], s3_storage::candidates(0));
        $DB->set_field('local_aireader_asset', 's3retryafter', time() - 1, ['id' => $coolingdown->id]);
        $this->assertSame([(int)$coolingdown->id], array_keys(s3_storage::candidates(1)));
        $this->assertSame([(int)$coolingdown->id, (int)$eligible->id], array_keys(s3_storage::candidates(2)));
    }

    /**
     * A crash after removing the Moodle file must not cause endless primary transfers.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_primary_repairs_stale_fileid_after_file_deletion(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $asset = asset_manager::get_by_id((int)$asset->id);
        storage::get_local_file($asset)->delete();
        $DB->set_field('local_aireader_asset', 's3lasterror', 'Earlier transfer failure', ['id' => $asset->id]);
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');

        $this->assertArrayHasKey($asset->id, s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $repaired = asset_manager::get_by_id((int)$asset->id);

        $this->assertNull($repaired->fileid);
        $this->assertNull($repaired->s3lasterror);
        $this->assertNotNull(s3_storage::get_remote($repaired));
        $this->assertSame([], s3_storage::candidates());
        $this->assertCount(1, $this->uploads);
        $this->assertSame([], $this->downloads);
    }

    /**
     * A crash after local restoration must be repairable without another transfer.
     *
     * @covers ::sync_asset
     * @covers ::candidates
     */
    public function test_mirror_repairs_missing_fileid_when_the_local_file_exists(): void {
        global $DB;
        set_config('storage_mode', s3_storage::MODE_MIRROR, 'local_aireader');
        $asset = $this->create_asset();
        s3_storage::sync_asset((int)$asset->id);
        $DB->set_field('local_aireader_asset', 'fileid', null, ['id' => $asset->id]);

        $this->assertArrayHasKey($asset->id, s3_storage::candidates());
        $this->assertTrue(s3_storage::sync_asset((int)$asset->id));
        $repaired = asset_manager::get_by_id((int)$asset->id);

        $this->assertSame((int)$asset->fileid, (int)$repaired->fileid);
        $this->assertNotNull(storage::get_local_file($repaired));
        $this->assertSame([], s3_storage::candidates());
        $this->assertCount(1, $this->uploads);
        $this->assertSame([], $this->downloads);
    }

    /**
     * Readers holding old asset snapshots resolve the currently committed remote object.
     *
     * @covers ::get_remote
     */
    public function test_remote_lookup_resolves_current_pointer_from_a_stale_snapshot(): void {
        set_config('storage_mode', s3_storage::MODE_PRIMARY, 'local_aireader');
        $snapshot = $this->create_asset();
        $this->assertNull($snapshot->s3objectid);
        s3_storage::sync_asset((int)$snapshot->id);
        $original = s3_storage::get_remote($snapshot);
        $this->assertNotNull($original);

        $snapshot->s3objectid = $original->id;
        set_config('s3_bucket', 'new-private-audio', 'local_aireader');
        s3_storage::sync_asset((int)$snapshot->id);
        $current = s3_storage::get_remote($snapshot);
        $this->assertNotSame($original->id, $current->id);
        $this->assertSame('new-private-audio', $current->bucket);

        $file = storage::store_mp3((int)$snapshot->id, (int)$snapshot->contextid, 'Replacement bytes');
        asset_manager::record_generated((int)$snapshot->id, $file->get_id(), $file->get_filesize(), 40, 500);
        $this->assertNull(s3_storage::get_remote($snapshot));
    }

    /**
     * Mixed-case prefixes must not alias another S3 key in a case-insensitive database.
     *
     * @covers ::destination
     */
    public function test_destination_rejects_uppercase_object_prefix(): void {
        set_config('s3_prefix', 'Narration', 'local_aireader');

        try {
            s3_storage::destination();
            $this->fail('An uppercase prefix must be rejected to avoid object-key collisions.');
        } catch (\moodle_exception $error) {
            $this->assertSame('error_s3_config', $error->errorcode);
        }
    }

    /**
     * The connection check writes, reads back and removes a throwaway object only.
     *
     * @covers ::test_connection
     */
    public function test_connection_check_round_trips_and_cleans_up(): void {
        $asset = $this->create_asset();
        $key = s3_storage::test_connection();

        $this->assertStringStartsWith(s3_storage::destination()['prefix'] . 'connection-test/', $key);
        $this->assertSame([['test-private-audio', $key]], $this->uploads);
        $this->assertSame([['test-private-audio', $key]], $this->downloads);
        $this->assertSame([['test-private-audio', $key]], $this->deletes);
        $this->assertSame([], $this->objects);
        $this->assertNotNull(storage::get_local_file($asset));

        $this->failupload = true;
        try {
            s3_storage::test_connection();
            $this->fail('A denied upload must be reported.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
        }
    }

    /**
     * Starting a migration selects primary mode, forgives cooldowns and queues one task.
     *
     * @covers ::start_migration
     * @covers ::count_candidates
     * @covers ::summary
     */
    public function test_start_migration_switches_to_primary_and_queues_once(): void {
        global $DB;
        $first = $this->create_asset();
        $second = $this->create_asset();
        $DB->set_field('local_aireader_asset', 's3retryafter', time() + HOURSECS, ['id' => $second->id]);
        $this->assertSame(0, s3_storage::count_candidates());

        $this->assertSame(2, s3_storage::start_migration());
        $this->assertSame(s3_storage::MODE_PRIMARY, s3_storage::mode());
        $this->assertNull($DB->get_field('local_aireader_asset', 's3retryafter', ['id' => $second->id]));
        $this->assertSame(2, s3_storage::start_migration());
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_aireader\task\migrate_s3::class));
        $this->assertSame([], $this->uploads);

        $summary = s3_storage::summary();
        $this->assertSame(2, $summary['local']);
        $this->assertSame(2, $summary['remaining']);
        $this->assertSame(2 * strlen('ID3 narration bytes'), $summary['bytes']);
        $this->assertNotNull(storage::get_local_file($first));
    }

    /**
     * The migration task offloads every asset and leaves failures in Moodle for retry.
     *
     * @covers \local_aireader\task\migrate_s3::execute
     */
    public function test_migration_task_offloads_all_audio_and_keeps_failures_local(): void {
        global $DB;
        $this->expectOutputRegex('/moved 3 asset\(s\); 0 failure\(s\).*will retry.*moved 0 asset\(s\); 1 failure\(s\)/s');
        $assets = [$this->create_asset(), $this->create_asset(), $this->create_asset()];
        s3_storage::start_migration();
        $this->runAdhocTasks(\local_aireader\task\migrate_s3::class);

        foreach ($assets as $asset) {
            $asset = asset_manager::get_by_id((int)$asset->id);
            $this->assertNull(storage::get_local_file($asset));
            $this->assertNull($asset->fileid);
            $this->assertNotNull(s3_storage::get_remote($asset));
        }
        $summary = s3_storage::summary();
        $this->assertSame(3, $summary['remote']);
        $this->assertSame(0, $summary['local']);
        $this->assertSame(0, $summary['remaining']);
        $this->assertNotEmpty(get_config('local_aireader', 's3_migration_finished'));
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(\local_aireader\task\migrate_s3::class));

        $failing = $this->create_asset();
        $this->failupload = true;
        s3_storage::start_migration();
        $this->runAdhocTasks(\local_aireader\task\migrate_s3::class);
        $failing = asset_manager::get_by_id((int)$failing->id);
        $this->assertNotNull(storage::get_local_file($failing));
        $this->assertNotNull($failing->s3lasterror);
        $this->assertGreaterThan(time(), (int)$failing->s3retryafter);
        // A failure waits out its cooldown instead of spinning a new task.
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(\local_aireader\task\migrate_s3::class));
        $this->assertSame(1, $DB->count_records_select('local_aireader_asset', 'fileid IS NOT NULL'));
    }

    /**
     * Create a ready page asset with real File API audio and historical generation metadata.
     *
     * @return \stdClass Asset record.
     */
    private function create_asset(): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance((int)$page->cmid);
        $assetid = $DB->insert_record('local_aireader_asset', (object)[
            'courseid' => $course->id, 'cmid' => $page->cmid, 'contextid' => $context->id,
            'module' => 'page', 'instanceid' => $page->id, 'chapterid' => 0,
            'lang' => 'en', 'voice' => 'marin', 'model' => 'gpt-4o-mini-tts',
            'sourcehash' => hash('sha256', 'page-' . $page->id),
            'status' => asset_manager::STATUS_READY, 'timecreated' => time() - DAYSECS,
            'timemodified' => time() - DAYSECS, 'lastgenerated' => time() - DAYSECS,
            'inputchars' => 6400, 'durationsecs' => 320,
        ]);
        $bytes = 'ID3 narration bytes';
        $file = storage::store_mp3((int)$assetid, (int)$context->id, $bytes);
        $DB->update_record('local_aireader_asset', (object)[
            'id' => $assetid, 'fileid' => $file->get_id(), 'bytesize' => strlen($bytes),
        ]);
        return asset_manager::get_by_id((int)$assetid);
    }
}
