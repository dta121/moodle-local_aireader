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
 * External-function tests for local_aireader_request_regen validation.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

/**
 * Tests for {@see request_regen}.
 *
 * @coversDefaultClass \local_aireader\external\request_regen
 */
final class request_regen_test extends \advanced_testcase {
    /**
     * Page regeneration requests must not accept Book chapter ids.
     *
     * @covers ::execute
     */
    public function test_rejects_page_request_with_chapterid(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        [$course, $cm] = $this->create_page();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        request_regen::execute((int)$cm->id, 'page', 123, 'en');
    }

    /**
     * Book regeneration targets a concrete chapter, never a synthetic
     * activity-level asset.
     *
     * @covers ::execute
     */
    public function test_rejects_book_request_without_chapterid(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        [$course, $cm] = $this->create_book_with_chapter();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        request_regen::execute((int)$cm->id, 'book', 0, 'en');
    }

    /**
     * A chapter id from another Book must be rejected before any asset is
     * marked stale or queued.
     *
     * @covers ::execute
     */
    public function test_rejects_cross_book_chapterid(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $bookone = $bookgen->create_instance(['course' => $course->id]);
        $booktwo = $bookgen->create_instance(['course' => $course->id]);
        $chapterintwo = $bookgen->create_chapter(['bookid' => $booktwo->id]);
        $cmone = get_coursemodule_from_instance('book', $bookone->id, $course->id, false, MUST_EXIST);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\dml_exception::class);
        request_regen::execute((int)$cmone->id, 'book', (int)$chapterintwo->id, 'en');
    }

    /**
     * Create a Page module.
     *
     * @return array{\stdClass,\stdClass} Course and cm.
     */
    private function create_page(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        return [$course, $cm];
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
