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
 * Tests for player bootstrap hook helpers.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader;

/**
 * Tests for {@see hook_callbacks}.
 *
 * @coversDefaultClass \local_aireader\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Default Book bootstrap skips hidden chapters for ordinary students.
     *
     * @covers ::resolve_book_chapter_for_player
     */
    public function test_default_book_chapter_skips_hidden_for_student(): void {
        $this->resetAfterTest();
        [$course, $cm, $hidden, $visible] = $this->create_book_with_hidden_first();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $context = \context_module::instance((int)$cm->id);

        $resolved = hook_callbacks::resolve_book_chapter_for_player($cm, $context, 0);

        $this->assertSame((int)$visible->id, $resolved);
    }

    /**
     * Users with hidden-chapter visibility should bootstrap the same first
     * chapter Moodle displays for them.
     *
     * @covers ::resolve_book_chapter_for_player
     */
    public function test_default_book_chapter_allows_hidden_for_teacher(): void {
        $this->resetAfterTest();
        [$course, $cm, $hidden] = $this->create_book_with_hidden_first();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $context = \context_module::instance((int)$cm->id);

        $resolved = hook_callbacks::resolve_book_chapter_for_player($cm, $context, 0);

        $this->assertSame((int)$hidden->id, $resolved);
    }

    /**
     * Requested hidden chapters remain gated by mod_book's hidden-chapter cap.
     *
     * @covers ::resolve_book_chapter_for_player
     */
    public function test_requested_hidden_chapter_rejected_for_student(): void {
        $this->resetAfterTest();
        [$course, $cm, $hidden] = $this->create_book_with_hidden_first();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $context = \context_module::instance((int)$cm->id);

        $this->expectException(\required_capability_exception::class);
        hook_callbacks::resolve_book_chapter_for_player($cm, $context, (int)$hidden->id);
    }

    /**
     * A chapter id from another Book must not be used to bootstrap this Book's
     * player configuration.
     *
     * @covers ::resolve_book_chapter_for_player
     */
    public function test_requested_cross_book_chapter_rejected(): void {
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
        $context = \context_module::instance((int)$cmone->id);

        $this->expectException(\dml_exception::class);
        hook_callbacks::resolve_book_chapter_for_player($cmone, $context, (int)$chapterintwo->id);
    }

    /**
     * Create a Book whose first chapter is hidden and second chapter is visible.
     *
     * mod_book's create_chapter generator inserts new chapters at pagenum=1 by
     * default and bumps every existing chapter with `pagenum >= 1` up by one,
     * which would silently invert the order this test cares about. Pin both
     * pagenums explicitly so iteration order matches the test's intent.
     *
     * @return array{\stdClass,\stdClass,\stdClass,\stdClass} Course, cm, hidden, visible.
     */
    private function create_book_with_hidden_first(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $hidden = $bookgen->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'hidden' => 1]);
        $visible = $bookgen->create_chapter(['bookid' => $book->id, 'pagenum' => 2]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);
        return [$course, $cm, $hidden, $visible];
    }
}
