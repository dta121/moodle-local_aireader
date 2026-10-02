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
 * Tests for deleting narration when source deletion overlaps a storage worker.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Source deletion must be recoverable after a busy worker releases its lock.
 *
 * @coversDefaultClass \local_aireader\manager\asset_manager
 */
final class s3_cleanup_test extends \advanced_testcase {
    /**
     * A skipped source-deletion event is recovered without losing remote cleanup.
     *
     * @covers ::purge_cm
     * @covers ::purge_orphaned
     */
    public function test_orphan_sweep_recovers_deletion_after_the_worker_releases_its_lock(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // Database row locks cannot recurse in one process, unlike advisory locks.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        set_config('storage_mode', 'local', 'local_aireader');
        $asset = $this->create_asset();
        $active = $this->create_asset();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_aireader_position', (object)[
            'userid' => $user->id, 'assetid' => $asset->id, 'position' => 5, 'timemodified' => time(),
        ]);
        $DB->insert_record('local_aireader_listen', (object)[
            'userid' => $user->id, 'assetid' => $asset->id, 'startms' => 0, 'endms' => 5000, 'timemodified' => time(),
        ]);
        $DB->insert_record('local_aireader_segment', (object)[
            'assetid' => $asset->id, 'idx' => 0, 'startms' => 0, 'endms' => 5000, 'segtext' => 'Narration.',
        ]);

        $lock = s3_storage::lock((int)$asset->id);
        $this->assertNotFalse($lock);
        try {
            // Model the source having already disappeared when its observer runs.
            $DB->delete_records('course_modules', ['id' => $asset->cmid]);
            asset_manager::purge_cm((int)$asset->cmid);
            $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $asset->id]));
            $this->assertSame(0, asset_manager::purge_orphaned());
        } finally {
            $lock->release();
        }

        $this->assertSame(1, asset_manager::purge_orphaned());
        $this->assertFalse($DB->record_exists('local_aireader_asset', ['id' => $asset->id]));
        $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $active->id]));
        $this->assertNull(storage::get_local_file($asset));
        foreach (['local_aireader_position', 'local_aireader_listen', 'local_aireader_segment'] as $table) {
            $this->assertFalse($DB->record_exists($table, ['assetid' => $asset->id]));
        }
        $this->assertSame('delete', $DB->get_field('local_aireader_s3', 'status', ['id' => $asset->s3objectid]));
        $this->assertSame(0, asset_manager::purge_orphaned());
    }

    /**
     * A chapter can disappear while its course module remains valid.
     *
     * @covers ::purge_orphaned
     */
    public function test_orphan_sweep_handles_deleted_chapters_and_respects_its_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $chapter = $this->create_asset('book');
        $page = $this->create_asset();
        $active = $this->create_asset();
        $DB->delete_records('book_chapters', ['id' => $chapter->chapterid]);
        $DB->delete_records('course_modules', ['id' => $page->cmid]);

        $this->assertSame(0, asset_manager::purge_orphaned(0));
        $this->assertSame(1, asset_manager::purge_orphaned(1));
        $this->assertFalse($DB->record_exists('local_aireader_asset', ['id' => $chapter->id]));
        $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $page->id]));
        $this->assertSame(1, asset_manager::purge_orphaned(1));
        $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $active->id]));
    }

    /**
     * Stale retention reports the actual count when another worker is busy.
     *
     * @covers ::purge_stale_older_than
     */
    public function test_stale_purge_counts_only_assets_it_could_lock(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $asset = $this->create_asset();
        $DB->update_record('local_aireader_asset', (object)[
            'id' => $asset->id, 'status' => asset_manager::STATUS_STALE, 'timemodified' => time() - WEEKSECS,
        ]);
        $lock = s3_storage::lock((int)$asset->id);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame(0, asset_manager::purge_stale_older_than(DAYSECS));
        } finally {
            $lock->release();
        }
        $this->assertSame(1, asset_manager::purge_stale_older_than(DAYSECS));
    }

    /**
     * Regeneration between selecting stale rows and acquiring the lock keeps audio.
     *
     * @covers ::purge_assets
     */
    public function test_stale_purge_rechecks_current_status_and_age_under_the_lock(): void {
        global $DB;
        $this->resetAfterTest();
        $ready = $this->create_asset();
        $recent = $this->create_asset();
        $cutoff = time() - DAYSECS;

        // These snapshots were eligible when the retention sweep selected them.
        foreach ([$ready, $recent] as $asset) {
            $asset->status = asset_manager::STATUS_STALE;
            $asset->timemodified = $cutoff - 1;
        }
        // The first finished generation; the second was just marked stale again.
        $DB->update_record('local_aireader_asset', (object)[
            'id' => $ready->id, 'status' => asset_manager::STATUS_READY, 'timemodified' => $cutoff - 1,
        ]);
        $DB->update_record('local_aireader_asset', (object)[
            'id' => $recent->id, 'status' => asset_manager::STATUS_STALE, 'timemodified' => $cutoff,
        ]);

        $purge = new \ReflectionMethod(asset_manager::class, 'purge_assets');
        $purge->setAccessible(true);
        $this->assertSame(0, $purge->invoke(null, [$ready, $recent], $cutoff));
        foreach ([$ready, $recent] as $asset) {
            $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $asset->id]));
            $this->assertInstanceOf(\stored_file::class, storage::get_local_file($asset));
            $this->assertSame('ready', $DB->get_field('local_aireader_s3', 'status', ['id' => $asset->s3objectid]));
        }
    }

    /**
     * Create an asset with both local audio and a persisted S3 copy.
     *
     * @param string $module Source module type.
     * @return \stdClass Asset record.
     */
    private function create_asset(string $module = 'page'): \stdClass {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $instance = $gen->create_module($module, ['course' => $course->id]);
        $chapterid = 0;
        if ($module === 'book') {
            $chapter = $gen->get_plugin_generator('mod_book')->create_chapter(['bookid' => $instance->id]);
            $chapterid = (int)$chapter->id;
        }
        $context = \context_module::instance((int)$instance->cmid);
        $now = time();
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$instance->cmid,
            'contextid' => (int)$context->id,
            'module' => $module,
            'instanceid' => (int)$instance->id,
            'chapterid' => $chapterid,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'gpt-4o-mini-tts',
            'sourcehash' => hash('sha256', 'module-' . $instance->cmid),
            'status' => asset_manager::STATUS_READY,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $asset->id = $DB->insert_record('local_aireader_asset', $asset);
        $file = storage::store_mp3((int)$asset->id, (int)$asset->contextid, 'Narration.');
        $asset->s3objectid = $DB->insert_record('local_aireader_s3', (object)[
            'assetid' => (int)$asset->id, 'bucket' => 'test-bucket', 'region' => 'us-east-1',
            'objectkey' => 'asset-' . $asset->id . '.mp3', 'contenthash' => $file->get_contenthash(),
            'filesize' => $file->get_filesize(), 'status' => 'ready', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->update_record('local_aireader_asset', (object)[
            'id' => $asset->id, 's3objectid' => $asset->s3objectid, 'fileid' => $file->get_id(),
        ]);
        return $asset;
    }
}
