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
 * Tests for listening-progress completion.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see completion_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\completion_manager
 */
final class completion_manager_test extends \advanced_testcase {
    /**
     * Overlapping played ranges are merged and counted once.
     *
     * @covers ::record_range
     * @covers ::listened_ms
     */
    public function test_record_range_merges_overlap(): void {
        $this->resetAfterTest();

        [$course, $cm, $asset] = $this->create_page_asset(100);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        completion_manager::record_range((int)$user->id, $asset, 0, 30000);
        completion_manager::record_range((int)$user->id, $asset, 20000, 60000);

        $this->assertSame(60000, completion_manager::listened_ms((int)$user->id, (int)$asset->id));
    }

    /**
     * Reaching the configured threshold marks the source activity complete.
     *
     * @covers ::maybe_complete
     */
    public function test_maybe_complete_marks_activity_complete_at_threshold(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('enable_completion', '1', 'local_aireader');

        [$course, $cm, $asset] = $this->create_page_asset(100, COMPLETION_TRACKING_MANUAL);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        completion_manager::set_config((int)$course->id, (int)$cm->id, true, 50, 0);
        completion_manager::record_range((int)$user->id, $asset, 0, 49999);
        $result = completion_manager::maybe_complete((int)$user->id, $asset);
        $this->assertFalse($result['completed']);
        $this->assertFalse($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $cm->id,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));

        completion_manager::record_range((int)$user->id, $asset, 49999, 50000);
        $result = completion_manager::maybe_complete((int)$user->id, $asset);

        $this->assertTrue($result['completed']);
        $this->assertTrue($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $cm->id,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }

    /**
     * Create a Page activity with a ready narration asset.
     *
     * @param int $durationsecs
     * @param int $completion
     * @return array{\stdClass,\stdClass,\stdClass} Course, cm, asset.
     */
    private function create_page_asset(
        int $durationsecs,
        int $completion = COMPLETION_TRACKING_NONE
    ): array {
        global $DB;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'completion' => $completion,
            'completionview' => COMPLETION_VIEW_NOT_REQUIRED,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $now = time();
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$cm->instance,
            'chapterid' => 0,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'gpt-4o-mini-tts',
            'sourcehash' => hash('sha256', 'completion manager test'),
            'status' => asset_manager::STATUS_READY,
            'fileid' => null,
            'bytesize' => null,
            'durationsecs' => $durationsecs,
            'inputchars' => null,
            'lasterror' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'lastgenerated' => $now,
            'lastrequested' => $now,
        ];
        $asset->id = $DB->insert_record('local_aireader_asset', $asset);

        return [$course, $cm, $asset];
    }
}
