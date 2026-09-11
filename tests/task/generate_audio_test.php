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
 * Tests for the retry behaviour of the narration generation task.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\manager\asset_manager;
use local_aireader\manager\content_extractor;

/**
 * Tests for {@see generate_audio}.
 *
 * Offline throughout: with no API key the speech client throws before it opens
 * a socket, which is a real failure for the catch block to classify.
 *
 * @coversDefaultClass \local_aireader\task\generate_audio
 */
final class generate_audio_test extends \advanced_testcase {
    /**
     * While attempts remain, an unclassified failure is rethrown so Moodle
     * reschedules it.
     *
     * @covers ::execute
     */
    public function test_failure_is_rethrown_while_attempts_remain(): void {
        $assetid = $this->create_pending_asset();

        $task = new generate_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(12);

        $this->expectException(\moodle_exception::class);
        $this->run_task($task);
    }

    /**
     * On the final attempt the run ends cleanly. This is the change that stops
     * new zero-attempt rows being created, whatever the failure turns out to be.
     *
     * @covers ::execute
     */
    public function test_last_attempt_exits_cleanly_instead_of_dying(): void {
        $assetid = $this->create_pending_asset();

        $task = new generate_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        $task->set_attempts_available(1);

        $output = $this->run_task($task);
        $this->assertStringContainsString('not retrying', $output);
    }

    /**
     * Giving up early must not cost visibility: the asset carries the error
     * either way, so the failure still reaches the dashboard.
     *
     * @covers ::execute
     */
    public function test_the_error_is_recorded_on_the_asset_either_way(): void {
        global $DB;

        foreach ([12, 1] as $attempts) {
            $assetid = $this->create_pending_asset();
            $task = new generate_audio();
            $task->set_custom_data(['assetid' => $assetid]);
            $task->set_attempts_available($attempts);

            try {
                $this->run_task($task);
            } catch (\moodle_exception $e) {
                $this->assertSame(12, $attempts);
            }

            $asset = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
            $this->assertSame(asset_manager::STATUS_ERROR, $asset->status, "attempts {$attempts}");
            $this->assertNotEmpty($asset->lasterror, "attempts {$attempts}");
        }
    }

    /**
     * A payload naming an asset that no longer exists completes rather than
     * failing, so a deleted activity cannot leave a task retrying.
     *
     * @covers ::execute
     */
    public function test_missing_asset_completes_without_failing(): void {
        $this->resetAfterTest();

        $task = new generate_audio();
        $task->set_custom_data(['assetid' => 999999]);
        $task->set_attempts_available(12);

        $output = $this->run_task($task);
        $this->assertStringContainsString('not found', $output);
    }

    /**
     * Execute a task with mtrace output captured so it does not leak into the
     * test runner's output.
     *
     * @param generate_audio $task Task to run.
     * @return string Everything the task traced.
     */
    private function run_task(generate_audio $task): string {
        ob_start();
        try {
            $task->execute();
        } finally {
            $output = (string)ob_get_clean();
        }
        return $output;
    }

    /**
     * Create a pending source-language page asset whose hash matches its page,
     * on a site with no API key, so synthesis fails without any network call.
     *
     * @return int Asset id.
     */
    private function create_pending_asset(): int {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('openai_api_key', '', 'local_aireader');
        set_config('enable_alignment', 0, 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course'  => $course->id,
            'content' => '<p>The kidneys filter blood and regulate fluid balance.</p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        // The task recomputes the hash and marks the row stale when it differs,
        // so the fixture has to agree with what the extractor actually produces.
        $extracted = content_extractor::extract('page', (int)$cm->id, null);
        $hash = asset_manager::compute_hash(
            'page',
            (int)$cm->id,
            null,
            'en',
            'marin',
            'gpt-4o-mini-tts',
            $extracted['text']
        );

        $now = time();
        return (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid'      => (int)$course->id,
            'cmid'          => (int)$cm->id,
            'contextid'     => (int)$context->id,
            'module'        => 'page',
            'instanceid'    => (int)$cm->instance,
            'chapterid'     => 0,
            'lang'          => 'en',
            'voice'         => 'marin',
            'model'         => 'gpt-4o-mini-tts',
            'sourcehash'    => $hash,
            'status'        => asset_manager::STATUS_PENDING,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastrequested' => $now,
        ]);
    }
}
