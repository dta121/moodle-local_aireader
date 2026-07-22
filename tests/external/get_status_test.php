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
 * External-function tests for local_aireader_get_status security gates.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

/**
 * Tests for {@see get_status}.
 *
 * Focused on the security gates added in v1.0:
 *  - lang allowlist (F2)
 *  - hidden chapter visibility (F1)
 *  - module type whitelist
 *
 * @coversDefaultClass \local_aireader\external\get_status
 */
final class get_status_test extends \advanced_testcase {
    /**
     * A lang code not in the configured allowlist must be rejected up front,
     * before any OpenAI work is queued.
     *
     * @covers ::execute
     */
    public function test_rejects_lang_not_in_allowlist(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en,es', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        get_status::execute((int)$cm->id, 'page', 0, 'fr');
    }

    /**
     * Source-language requests for a page module pass the parameter gate
     * and reach the cache/queue path.
     *
     * @covers ::execute
     */
    public function test_accepts_allowed_lang_on_page(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en,es', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = get_status::execute((int)$cm->id, 'page', 0, 'en');
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('assetid', $result);
    }

    /**
     * Hidden book chapters must be rejected for users without
     * `mod/book:viewhiddenchapters`.
     *
     * @covers ::execute
     */
    public function test_rejects_hidden_chapter_for_student(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $hidden = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_status::execute((int)$cm->id, 'book', (int)$hidden->id, 'en');
    }

    /**
     * Teachers (who have `mod/book:viewhiddenchapters`) can still request
     * audio for hidden chapters.
     *
     * @covers ::execute
     */
    public function test_allows_hidden_chapter_for_teacher(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $hidden = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);

        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_status::execute((int)$cm->id, 'book', (int)$hidden->id, 'en');
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * A student must not be able to queue generation on a scope where the
     * teacher has switched narration off, even by calling the WS directly.
     *
     * @covers ::execute
     */
    public function test_rejects_disabled_override_for_student(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        \local_aireader\manager\override_manager::set((int)$course->id, (int)$cm->id, 0, false);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_narration_disabled', 'local_aireader'));
        get_status::execute((int)$cm->id, 'page', 0, 'en');
    }

    /**
     * A manager-capable user keeps access on an override-disabled scope so
     * they can verify narration before re-enabling it.
     *
     * @covers ::execute
     */
    public function test_allows_disabled_override_for_teacher(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        \local_aireader\manager\override_manager::set((int)$course->id, (int)$cm->id, 0, false);

        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_status::execute((int)$cm->id, 'page', 0, 'en');
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * The plugin master switch turns the WS off for everyone, managers included.
     *
     * @covers ::execute
     */
    public function test_rejects_when_plugin_disabled(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        set_config('enabled', 0, 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_narration_disabled', 'local_aireader'));
        get_status::execute((int)$cm->id, 'page', 0, 'en');
    }

    /**
     * The per-module switch (enable_page) also gates the WS for everyone.
     *
     * @covers ::execute
     */
    public function test_rejects_when_module_type_disabled(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        set_config('enable_page', 0, 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_narration_disabled', 'local_aireader'));
        get_status::execute((int)$cm->id, 'page', 0, 'en');
    }

    /**
     * A voice outside the enabled set must be rejected up front, before any
     * OpenAI work is queued — every (language, voice) pair is billed.
     *
     * @covers ::execute
     */
    public function test_rejects_voice_not_enabled(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        set_config('voice', 'marin', 'local_aireader');
        set_config('enabled_voices', 'marin,alloy', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        get_status::execute((int)$cm->id, 'page', 0, 'en', 'onyx');
    }

    /**
     * An enabled non-default voice passes the gate and resolves its own asset,
     * distinct from the default voice's asset.
     *
     * @covers ::execute
     */
    public function test_accepts_enabled_voice(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');
        set_config('voice', 'marin', 'local_aireader');
        set_config('enabled_voices', 'marin,alloy', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        $default = get_status::execute((int)$cm->id, 'page', 0, 'en');
        $alloy = get_status::execute((int)$cm->id, 'page', 0, 'en', 'alloy');
        $this->assertArrayHasKey('assetid', $alloy);
        $this->assertNotEquals($default['assetid'], $alloy['assetid']);
    }

    /**
     * Modules other than page/book are rejected.
     *
     * @covers ::execute
     */
    public function test_rejects_unsupported_module(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en', 'local_aireader');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);

        // The PARAM_ALPHA parameter validation on `module` will reject 'forum'
        // via the module whitelist check before any cm lookup.
        $this->expectException(\invalid_parameter_exception::class);
        get_status::execute((int)$cm->id, 'forum', 0, 'en');
    }
}
