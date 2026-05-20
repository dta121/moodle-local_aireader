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
     * Page/no-chapter assets must hash and store consistently whether the
     * caller passes null or 0 for chapterid.
     *
     * @covers ::compute_hash
     * @covers ::ensure_row
     * @covers ::find_current
     */
    public function test_page_assets_normalise_chapterid_to_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        $hashfromnull = asset_manager::compute_hash('page', (int)$cm->id, null, 'en', 'marin', 'm1', 'Text');
        $hashfromzero = asset_manager::compute_hash('page', (int)$cm->id, 0, 'en', 'marin', 'm1', 'Text');
        $this->assertSame($hashfromnull, $hashfromzero);

        [$first, $matchedfirst] = asset_manager::ensure_row([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$cm->instance,
            'chapterid' => null,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'm1',
            'sourcehash' => $hashfromnull,
        ]);
        [$second, $matchedsecond] = asset_manager::ensure_row([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$cm->instance,
            'chapterid' => 0,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'm1',
            'sourcehash' => $hashfromzero,
        ]);

        $this->assertFalse($matchedfirst);
        $this->assertTrue($matchedsecond);
        $this->assertSame((int)$first->id, (int)$second->id);
        $this->assertSame(
            1,
            $DB->count_records('local_aireader_asset', ['cmid' => $cm->id, 'chapterid' => 0])
        );
    }

    /**
     * Asset-id entry points must not accept an asset row under an unrelated
     * module context.
     *
     * @covers ::assert_asset_visible
     */
    public function test_assert_asset_visible_rejects_context_mismatch(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $pageone = $gen->create_module('page', ['course' => $course->id]);
        $pagetwo = $gen->create_module('page', ['course' => $course->id]);
        $cmone = get_coursemodule_from_instance('page', $pageone->id, $course->id, false, MUST_EXIST);
        $cmtwo = get_coursemodule_from_instance('page', $pagetwo->id, $course->id, false, MUST_EXIST);
        $contextone = \context_module::instance((int)$cmone->id);
        $contexttwo = \context_module::instance((int)$cmtwo->id);
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cmone->id,
            'contextid' => (int)$contextone->id,
            'module' => 'page',
            'chapterid' => 0,
        ];

        $this->expectException(\invalid_parameter_exception::class);
        asset_manager::assert_asset_visible($asset, $contexttwo);
    }

    /**
     * Page assets must not carry a Book chapter id; a corrupt row should fail
     * closed instead of being treated as a valid Page asset.
     *
     * @covers ::assert_asset_visible
     */
    public function test_assert_asset_visible_rejects_page_chapterid(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'chapterid' => 123,
        ];

        $this->expectException(\invalid_parameter_exception::class);
        asset_manager::assert_asset_visible($asset, $context);
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
