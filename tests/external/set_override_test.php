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
 * External-function tests for local_aireader_set_override validation.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

/**
 * Tests for {@see set_override}.
 *
 * @coversDefaultClass \local_aireader\external\set_override
 */
final class set_override_test extends \advanced_testcase {
    /**
     * Activity-level Book overrides still use chapterid 0.
     *
     * @covers ::execute
     */
    public function test_allows_book_activity_override(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->create_book_with_chapter();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = set_override::execute((int)$cm->id, 'book', 0, false);

        $this->assertFalse($result['enabled']);
        $this->assertSame('activity', $result['scope']);
        $this->assertTrue($DB->record_exists('local_aireader_override', [
            'cmid' => (int)$cm->id,
            'chapterid' => 0,
        ]));
    }

    /**
     * Chapter-level Book overrides must target a chapter in that same Book.
     *
     * @covers ::execute
     */
    public function test_rejects_cross_book_chapterid(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $bookone = $bookgen->create_instance(['course' => $course->id]);
        $booktwo = $bookgen->create_instance(['course' => $course->id]);
        $chapterintwo = $bookgen->create_chapter(['bookid' => $booktwo->id]);
        $cmone = get_coursemodule_from_instance('book', $bookone->id, $course->id, false, MUST_EXIST);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        try {
            set_override::execute((int)$cmone->id, 'book', (int)$chapterintwo->id, false);
            $this->fail('Expected cross-book chapter id to be rejected.');
        } catch (\dml_exception $e) {
            $this->assertFalse($DB->record_exists('local_aireader_override', [
                'cmid' => (int)$cmone->id,
            ]));
        }
    }

    /**
     * Same-book chapter overrides still work for managers.
     *
     * @covers ::execute
     */
    public function test_allows_same_book_chapter_override(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $chapter] = $this->create_book_with_chapter();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = set_override::execute((int)$cm->id, 'book', (int)$chapter->id, false);

        $this->assertFalse($result['enabled']);
        $this->assertSame('chapter', $result['scope']);
        $this->assertTrue($DB->record_exists('local_aireader_override', [
            'cmid' => (int)$cm->id,
            'chapterid' => (int)$chapter->id,
        ]));
    }

    /**
     * Create a Book module with one visible chapter.
     *
     * @return array{\stdClass,\stdClass,\stdClass} Course, cm, and chapter.
     */
    private function create_book_with_chapter(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $chapter = $bookgen->create_chapter(['bookid' => $book->id]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);
        return [$course, $cm, $chapter];
    }
}
