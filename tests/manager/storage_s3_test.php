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
 * Tests for consuming local and S3-only narration audio.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Reads remain available after storage settings change, without public URLs.
 *
 * @coversDefaultClass \local_aireader\manager\storage
 */
final class storage_s3_test extends \advanced_testcase {
    /**
     * Enable the normal course-download gates for every fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_aireader');
        set_config('enable_page', 1, 'local_aireader');
        set_config('allow_downloads', 1, 'local_aireader');
        set_config('enabled_languages', 'en', 'local_aireader');
    }

    /**
     * Do not let a mocked transfer client escape into the next test.
     */
    protected function tearDown(): void {
        s3_storage::set_client_factory(null);
        parent::tearDown();
    }

    /**
     * Remote-only assets still expose the access-controlled Moodle URL.
     *
     * @covers ::has_audio
     * @covers ::get_audio_url
     * @covers ::get_download_url
     */
    public function test_remote_urls_do_not_require_aws_or_expose_the_bucket(): void {
        [, $asset] = $this->create_remote_asset('Audio bytes');
        $this->forbid_remote_downloads();

        $this->assertTrue(storage::has_audio($asset));
        $url = storage::get_audio_url($asset)->out(false);
        $download = storage::get_download_url($asset)->out(false);
        $this->assertStringContainsString('/pluginfile.php', $url);
        $this->assertStringContainsString(
            '/local_aireader/audio/' . $asset->id . '/asset-' . $asset->id . '.mp3',
            rawurldecode($url)
        );
        $this->assertStringNotContainsString('old-bucket', $url);
        $this->assertStringContainsString('forcedownload=1', $download);
    }

    /**
     * Old audio uses its saved destination even after S3 writing is disabled.
     *
     * @covers ::get_audio_content
     * @covers ::copy_audio_to_path
     */
    public function test_remote_reads_survive_a_storage_mode_and_bucket_change(): void {
        [, $asset] = $this->create_remote_asset('Saved narration');
        set_config('storage_mode', 'local', 'local_aireader');
        set_config('s3_bucket', 'different-bucket', 'local_aireader');
        set_config('s3_region', 'eu-west-1', 'local_aireader');
        $this->provide_remote_content($asset, 'Saved narration');

        $this->assertNull(storage::get_local_file($asset));
        $this->assertSame('Saved narration', storage::get_audio_content($asset));
        $this->assertNull(storage::get_local_file($asset));
    }

    /**
     * A corrupt object of the correct size must never reach alignment or a ZIP.
     *
     * @covers ::copy_audio_to_path
     */
    public function test_remote_checksum_mismatch_removes_the_download(): void {
        [, $asset] = $this->create_remote_asset('correct');
        $this->provide_remote_content($asset, 'corrupt');
        $path = make_request_directory() . '/audio.mp3';

        try {
            storage::copy_audio_to_path($asset, $path);
            $this->fail('Corrupt audio was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_s3_integrity', $e->errorcode);
        }
        $this->assertFileDoesNotExist($path);
    }

    /**
     * A truncated download also fails closed and leaves no partial local copy.
     *
     * @covers ::copy_audio_to_path
     */
    public function test_remote_size_mismatch_removes_the_download(): void {
        [, $asset] = $this->create_remote_asset('Complete narration');
        $this->provide_remote_content($asset, 'Complete');
        $path = make_request_directory() . '/audio.mp3';

        try {
            storage::copy_audio_to_path($asset, $path);
            $this->fail('Truncated audio was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_s3_integrity', $e->errorcode);
        }
        $this->assertFileDoesNotExist($path);
    }

    /**
     * Mirror mode can keep using Moodle storage without contacting AWS.
     *
     * @covers ::get_local_file
     * @covers ::get_audio_content
     * @covers ::copy_audio_to_path
     */
    public function test_local_audio_takes_precedence_over_the_remote_copy(): void {
        [, $asset] = $this->create_remote_asset('Remote narration');
        storage::store_mp3((int)$asset->id, (int)$asset->contextid, 'Local narration');
        $this->forbid_remote_downloads();

        $this->assertInstanceOf(\stored_file::class, storage::get_local_file($asset));
        $this->assertSame('Local narration', storage::get_audio_content($asset));
        $path = make_request_directory() . '/audio.mp3';
        storage::copy_audio_to_path($asset, $path);
        $this->assertSame('Local narration', file_get_contents($path));
    }

    /**
     * Course previews list S3-only audio after access checks without fetching it.
     *
     * @covers \local_aireader\manager\download_manager::collect_for_course
     */
    public function test_course_collection_lists_remote_audio_without_downloading(): void {
        [$course, $asset] = $this->create_remote_asset('Remote narration');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->forbid_remote_downloads();

        $items = download_manager::collect_for_course($course, (int)$student->id);
        $this->assertCount(1, $items);
        $this->assertSame((int)$asset->id, $items[0]->assetid);
        $this->assertNull($items[0]->file);
        $this->assertSame(strlen('Remote narration'), $items[0]->bytesize);
        $this->assertSame((int)$asset->id, (int)$items[0]->asset->id);

        override_manager::set((int)$course->id, (int)$asset->cmid, 0, false);
        $this->assertSame([], download_manager::collect_for_course($course, (int)$student->id));
    }

    /**
     * Constructing a transport would be a network dependency in a metadata read.
     */
    private function forbid_remote_downloads(): void {
        s3_storage::set_client_factory(static function (string $region): s3_client {
            throw new \coding_exception('A metadata or local-file read attempted to contact S3.');
        });
    }

    /**
     * Provide controlled download bytes while checking the persisted destination.
     *
     * @param \stdClass $asset Asset whose object will be downloaded.
     * @param string $bytes Bytes returned by the remote transport.
     */
    private function provide_remote_content(\stdClass $asset, string $bytes): void {
        $client = $this->getMockBuilder(s3_client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $client->expects($this->once())
            ->method('download_file')
            ->with('old-bucket', 'old/asset-' . $asset->id . '.mp3', $this->isType('string'))
            ->willReturnCallback(static function (string $bucket, string $key, string $path) use ($bytes): void {
                file_put_contents($path, $bytes);
            });
        s3_storage::set_client_factory(function (string $region) use ($client): s3_client {
            $this->assertSame('us-west-2', $region);
            return $client;
        });
    }

    /**
     * Build a ready asset with a persisted remote object and no Moodle MP3.
     *
     * @param string $bytes Expected object contents.
     * @return array Course and asset records.
     */
    private function create_remote_asset(string $bytes): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $now = time();
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$page->id,
            'chapterid' => 0,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'gpt-4o-mini-tts',
            'sourcehash' => hash('sha256', 'page-' . $page->id),
            'status' => asset_manager::STATUS_READY,
            'bytesize' => strlen($bytes),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $asset->id = $DB->insert_record('local_aireader_asset', $asset);
        $asset->s3objectid = $DB->insert_record('local_aireader_s3', (object)[
            'assetid' => (int)$asset->id,
            'bucket' => 'old-bucket',
            'region' => 'us-west-2',
            'objectkey' => 'old/asset-' . $asset->id . '.mp3',
            'contenthash' => sha1($bytes),
            'filesize' => strlen($bytes),
            'status' => 'ready',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('local_aireader_asset', 's3objectid', $asset->s3objectid, ['id' => $asset->id]);
        return [$course, $asset];
    }
}
