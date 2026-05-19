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
 * Tests for asset_manager helpers: enabled_languages parsing,
 * max_narration_chars defaulting, and the chapter-visibility gate.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see asset_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\asset_manager
 */
final class asset_manager_test extends \advanced_testcase {
    /**
     * Comma- and whitespace-separated lang codes are normalised; duplicates
     * are dropped; empty settings fall back to English.
     *
     * @covers ::enabled_languages
     */
    public function test_enabled_languages_parses_setting(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en, es,fr  ;pt_br', 'local_aireader');
        $this->assertSame(['en', 'es', 'fr', 'pt_br'], asset_manager::enabled_languages());

        set_config('enabled_languages', 'en, en, es, es', 'local_aireader');
        $this->assertSame(['en', 'es'], asset_manager::enabled_languages());

        set_config('enabled_languages', '', 'local_aireader');
        $this->assertSame(['en'], asset_manager::enabled_languages());
    }

    /**
     * `max_narration_chars` defaults when unset or non-positive; admin
     * overrides are honoured otherwise.
     *
     * @covers ::max_narration_chars
     */
    public function test_max_narration_chars_defaults_and_overrides(): void {
        $this->resetAfterTest();
        unset_config('max_narration_chars', 'local_aireader');
        $this->assertSame(asset_manager::DEFAULT_MAX_NARRATION_CHARS, asset_manager::max_narration_chars());

        set_config('max_narration_chars', 0, 'local_aireader');
        $this->assertSame(asset_manager::DEFAULT_MAX_NARRATION_CHARS, asset_manager::max_narration_chars());

        set_config('max_narration_chars', 12345, 'local_aireader');
        $this->assertSame(12345, asset_manager::max_narration_chars());
    }

    /**
     * Visible chapters always pass the gate; hidden chapters require the
     * `mod/book:viewhiddenchapters` capability on the cm context.
     *
     * @covers ::assert_chapter_visible
     */
    public function test_assert_chapter_visible_enforces_book_visibility(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $visiblechapter = $bookgen->create_chapter(['bookid' => $book->id]);
        $hiddenchapter = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');

        // Visible chapter: passes for student.
        $this->setUser($student);
        asset_manager::assert_chapter_visible($cm, (int)$visiblechapter->id, $context);
        $this->assertTrue(true);

        // Hidden chapter: passes for teacher (has viewhiddenchapters).
        $this->setUser($teacher);
        asset_manager::assert_chapter_visible($cm, (int)$hiddenchapter->id, $context);
        $this->assertTrue(true);

        // Hidden chapter: rejected for student.
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        asset_manager::assert_chapter_visible($cm, (int)$hiddenchapter->id, $context);
    }

    /**
     * A chapter id that doesn't belong to the supplied cm's book raises a
     * dml exception (MUST_EXIST). This protects against a learner passing
     * a chapter id from an unrelated book.
     *
     * @covers ::assert_chapter_visible
     */
    public function test_assert_chapter_visible_rejects_cross_book_chapter(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $bookone = $bookgen->create_instance(['course' => $course->id]);
        $booktwo = $bookgen->create_instance(['course' => $course->id]);
        $chapterintwo = $bookgen->create_chapter(['bookid' => $booktwo->id]);

        $cmone = get_coursemodule_from_instance('book', $bookone->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cmone->id);

        $this->setUser($gen->create_and_enrol($course, 'editingteacher'));

        $this->expectException(\dml_exception::class);
        asset_manager::assert_chapter_visible($cmone, (int)$chapterintwo->id, $context);
    }
}
