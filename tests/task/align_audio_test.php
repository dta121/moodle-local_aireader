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
 * Tests for the retry behaviour of the alignment task.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\manager\asset_manager;
use local_aireader\manager\storage;

/**
 * Tests for {@see align_audio}.
 *
 * Every case here runs offline. With no API key configured, the aligner throws
 * before it opens a socket, which gives a real failure to drive the catch block
 * with and no network dependency.
 *
 * @coversDefaultClass \local_aireader\task\align_audio
 */
final class align_audio_test extends \advanced_testcase {
    /**
     * With attempts to spare, a failure that is not classified as permanent is
     * rethrown so cron tries again.
     *
     * @covers ::execute
     */
    public function test_failure_is_rethrown_while_attempts_remain(): void {
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(12);

        $this->expectException(\moodle_exception::class);
        $this->run_task($task);
    }

    /**
     * On the final attempt the same failure ends the run cleanly. Rethrowing
     * here is what would strand the row at zero attempts, where cron ignores it
     * and it goes on blocking re-queues for this asset.
     *
     * @covers ::execute
     */
    public function test_last_attempt_exits_cleanly_instead_of_dying(): void {
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(1);

        $output = $this->run_task($task);
        $this->assertStringContainsString('not retrying', $output);
    }

    /**
     * A row that has already given up never rethrows, which is what makes the
     * admin "Run now" recovery a guaranteed clear rather than a coin flip.
     *
     * @covers ::execute
     */
    public function test_exhausted_row_exits_cleanly(): void {
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(0);

        $output = $this->run_task($task);
        $this->assertStringContainsString('not retrying', $output);
    }

    /**
     * Alignment is an enhancement, so a failure must leave the narration itself
     * playable rather than flipping the asset into an error state.
     *
     * @covers ::execute
     */
    public function test_alignment_failure_leaves_the_asset_ready(): void {
        global $DB;
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(1);
        $this->run_task($task);

        $this->assertSame(
            asset_manager::STATUS_READY,
            $DB->get_field('local_aireader_asset', 'status', ['id' => $assetid])
        );
    }

    /**
     * Giving up has to leave a trace on the asset.
     *
     * The task row is deleted the moment the failure is judged terminal, and
     * before this the only record was cron output, pruned after
     * task_logretention days. An asset could therefore end up permanently
     * without karaoke highlighting with nothing anywhere to say why.
     *
     * @covers ::execute
     */
    public function test_a_terminal_failure_is_recorded_on_the_asset(): void {
        global $DB;
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(1);
        $this->run_task($task);

        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertNotEmpty($row->lasterror);
        $this->assertSame(1, (int)$row->alignfailcount);
        $this->assertGreaterThan(time(), (int)$row->alignretryafter);
    }

    /**
     * Having given up, the next page view must not immediately buy another
     * attempt at the same transcription. Nothing re-queued alignment at all
     * before 1.8.1; now that something does, it needs a limit.
     *
     * @covers ::execute
     */
    public function test_giving_up_starts_a_cooldown_on_requeueing(): void {
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(1);
        $this->run_task($task);

        $this->assertFalse(asset_manager::queue_alignment($assetid));
        $this->assertTrue(asset_manager::queue_alignment($assetid, true));
    }

    /**
     * A failure that is still going to be retried is not recorded as a
     * give-up, or a long outage would inflate the backoff on every attempt.
     *
     * @covers ::execute
     */
    public function test_a_retried_failure_does_not_start_a_cooldown(): void {
        global $DB;
        $assetid = $this->create_ready_asset();

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(12);

        try {
            $this->run_task($task);
            $this->fail('Expected the failure to be rethrown');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(0, (int)$row->alignfailcount);
        $this->assertNull($row->alignretryafter);
    }

    /**
     * A clean skip with nothing to align still counts as giving up.
     *
     * get_status re-queues alignment for any ready asset without segments, so
     * a skip that stored nothing and started no cool-down would be scheduled
     * again on every page view for the rest of the asset's life.
     *
     * @covers ::execute
     */
    public function test_a_skip_with_no_audio_starts_a_cooldown(): void {
        global $DB;
        $assetid = $this->create_ready_asset();
        $contextid = (int)$DB->get_field('local_aireader_asset', 'contextid', ['id' => $assetid]);
        get_file_storage()->delete_area_files($contextid, storage::COMPONENT, storage::FILEAREA, $assetid);

        $task = new align_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(12);
        $output = $this->run_task($task);

        $this->assertStringContainsString('no stored mp3', $output);
        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(asset_manager::STATUS_READY, $row->status);
        $this->assertSame(1, (int)$row->alignfailcount);
        $this->assertNotEmpty($row->lasterror);
        $this->assertFalse(asset_manager::queue_alignment($assetid));
    }

    /**
     * Execute a task with mtrace output captured so it does not leak into the
     * test runner's output.
     *
     * @param align_audio $task Task to run.
     * @return string Everything the task traced.
     */
    private function run_task(align_audio $task): string {
        ob_start();
        try {
            $task->execute();
        } finally {
            $output = (string)ob_get_clean();
        }
        return $output;
    }

    /**
     * Create a ready page asset with a stored mp3, on a site with alignment on
     * and no API key, so alignment fails without touching the network.
     *
     * @return int Asset id.
     */
    private function create_ready_asset(): int {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enable_alignment', 1, 'local_aireader');
        set_config('openai_api_key', '', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        $now = time();
        $assetid = (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid'      => (int)$course->id,
            'cmid'          => (int)$cm->id,
            'contextid'     => (int)$context->id,
            'module'        => 'page',
            'instanceid'    => (int)$cm->instance,
            'chapterid'     => 0,
            'lang'          => 'en',
            'voice'         => 'marin',
            'model'         => 'gpt-4o-mini-tts',
            'sourcehash'    => hash('sha256', 'align test'),
            'status'        => asset_manager::STATUS_READY,
            'bytesize'      => 32,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastgenerated' => $now,
            'lastrequested' => $now,
        ]);

        get_file_storage()->create_file_from_string([
            'contextid' => (int)$context->id,
            'component' => storage::COMPONENT,
            'filearea'  => storage::FILEAREA,
            'itemid'    => $assetid,
            'filepath'  => '/',
            'filename'  => "asset-{$assetid}.mp3",
            'mimetype'  => 'audio/mpeg',
        ], str_repeat('a', 32));

        return $assetid;
    }
}
