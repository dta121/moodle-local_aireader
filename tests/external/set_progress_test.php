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
 * External-function tests for local_aireader_set_progress.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

use local_aireader\manager\asset_manager;
use local_aireader\manager\completion_manager;

/**
 * Tests for {@see set_progress}.
 *
 * @coversDefaultClass \local_aireader\external\set_progress
 */
final class set_progress_test extends \advanced_testcase {
    /**
     * The service stores resume position, listened progress, and completion.
     *
     * @covers ::execute
     */
    public function test_execute_records_progress_and_marks_completion(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('enable_completion', '1', 'local_aireader');

        [$course, $cm, $asset] = $this->create_page_asset(10);
        completion_manager::set_config((int)$course->id, (int)$cm->id, true, 50, 0);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $first = set_progress::execute((int)$asset->id, 3, 0, 3000);
        $this->assertTrue($first['success']);
        $this->assertSame(3, $first['position']);
        $this->assertSame(3000, $first['listenedms']);
        $this->assertSame(30, $first['percent']);
        $this->assertFalse($first['completed']);

        $second = set_progress::execute((int)$asset->id, 5, 3000, 5000);
        $this->assertTrue($second['success']);
        $this->assertSame(5000, $second['listenedms']);
        $this->assertSame(50, $second['percent']);
        $this->assertTrue($second['completed']);

        $this->assertSame(5, (int)$DB->get_field('local_aireader_position', 'position', [
            'userid' => $student->id,
            'assetid' => $asset->id,
        ]));
        $this->assertTrue($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $cm->id,
            'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }

    /**
     * Create a Page activity with manual completion and a ready narration asset.
     *
     * @param int $durationsecs
     * @return array{\stdClass,\stdClass,\stdClass} Course, cm, asset.
     */
    private function create_page_asset(int $durationsecs): array {
        global $DB;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
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
            'sourcehash' => hash('sha256', 'set progress test'),
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
