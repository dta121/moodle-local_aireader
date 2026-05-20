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
 * External-function tests for local_aireader_get_transcript security gates.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

use local_aireader\manager\asset_manager;
use local_aireader\manager\segment_manager;

/**
 * Tests for {@see get_transcript}.
 *
 * @coversDefaultClass \local_aireader\external\get_transcript
 */
final class get_transcript_test extends \advanced_testcase {
    /**
     * Hidden-chapter assets must not leak aligned text to ordinary listeners.
     *
     * @covers ::execute
     */
    public function test_rejects_hidden_chapter_asset_for_student(): void {
        $this->resetAfterTest();
        [$course, $cm, $hidden] = $this->create_book_with_hidden_chapter();
        $asset = $this->create_asset($course, $cm, (int)$hidden->id);
        segment_manager::store_for_asset((int)$asset->id, [[
            'startms' => 0,
            'endms' => 1000,
            'segtext' => 'Hidden chapter text',
        ]]);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_transcript::execute((int)$asset->id);
    }

    /**
     * Users who can view hidden Book chapters may still load their transcripts.
     *
     * @covers ::execute
     */
    public function test_allows_hidden_chapter_asset_for_teacher(): void {
        $this->resetAfterTest();
        [$course, $cm, $hidden] = $this->create_book_with_hidden_chapter();
        $asset = $this->create_asset($course, $cm, (int)$hidden->id);
        segment_manager::store_for_asset((int)$asset->id, [[
            'startms' => 0,
            'endms' => 1000,
            'segtext' => 'Hidden chapter text',
        ]]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_transcript::execute((int)$asset->id);
        $this->assertTrue($result['aligned']);
        $this->assertSame('Hidden chapter text', $result['segments'][0]['text']);
    }

    /**
     * Create a Book with one hidden chapter.
     *
     * @return array{\stdClass,\stdClass,\stdClass} Course, cm, hidden chapter.
     */
    private function create_book_with_hidden_chapter(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $hidden = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);
        return [$course, $cm, $hidden];
    }

    /**
     * Create a ready local_aireader asset row.
     *
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param int $chapterid
     * @return \stdClass
     */
    private function create_asset(\stdClass $course, \stdClass $cm, int $chapterid): \stdClass {
        global $DB;
        $context = \context_module::instance((int)$cm->id);
        $now = time();
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'book',
            'instanceid' => (int)$cm->instance,
            'chapterid' => $chapterid,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'gpt-4o-mini-tts',
            'sourcehash' => hash('sha256', 'hidden chapter'),
            'status' => asset_manager::STATUS_READY,
            'fileid' => null,
            'bytesize' => null,
            'durationsecs' => null,
            'lasterror' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'lastgenerated' => $now,
            'lastrequested' => $now,
        ];
        $asset->id = $DB->insert_record('local_aireader_asset', $asset);
        return $asset;
    }
}
