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
 * Tests for download_manager: which course narration a learner may download,
 * the per-asset access gating, and the size helpers.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see download_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\download_manager
 */
final class download_manager_test extends \advanced_testcase {
    /**
     * Set up sensible plugin defaults for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_aireader');
        set_config('enable_page', 1, 'local_aireader');
        set_config('enable_book', 1, 'local_aireader');
        set_config('allow_downloads', 1, 'local_aireader');
        set_config('enabled_languages', 'en,es', 'local_aireader');
    }

    /**
     * Insert a ready asset row plus its stored mp3 for a course module.
     *
     * @param \stdClass $course Course record.
     * @param \cm_info|\stdClass $cm Course module.
     * @param int $chapterid Book chapter id, or 0 for a page.
     * @param string $lang Language code.
     * @param string $status Asset status (defaults to ready).
     * @param int $bytes File size to write.
     * @return int The new asset id.
     */
    private function make_asset(
        \stdClass $course,
        $cm,
        int $chapterid,
        string $lang,
        string $status = asset_manager::STATUS_READY,
        int $bytes = 1024
    ): int {
        global $DB;
        $context = \context_module::instance((int)$cm->id);
        $now = time();
        $asset = (object)[
            'courseid'     => (int)$course->id,
            'cmid'         => (int)$cm->id,
            'contextid'    => (int)$context->id,
            'module'       => $cm->modname ?? $cm->modulename,
            'instanceid'   => (int)$cm->instance,
            'chapterid'    => $chapterid,
            'lang'         => $lang,
            'voice'        => 'marin',
            'model'        => 'gpt-4o-mini-tts',
            'sourcehash'   => hash('sha256', "{$cm->id}|{$chapterid}|{$lang}"),
            'status'       => $status,
            'bytesize'     => $bytes,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $asset->id = $DB->insert_record('local_aireader_asset', $asset);

        if ($status === asset_manager::STATUS_READY) {
            $fs = get_file_storage();
            $fs->create_file_from_string([
                'contextid' => (int)$context->id,
                'component' => storage::COMPONENT,
                'filearea'  => storage::FILEAREA,
                'itemid'    => (int)$asset->id,
                'filepath'  => '/',
                'filename'  => "asset-{$asset->id}.mp3",
                'mimetype'  => 'audio/mpeg',
            ], str_repeat('a', $bytes));
        }

        return (int)$asset->id;
    }

    /**
     * A ready page asset is offered; a pending one is not.
     *
     * @covers ::collect_for_course
     */
    public function test_collect_returns_only_ready_assets(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $readypage = $gen->create_module('page', ['course' => $course->id]);
        $pendingpage = $gen->create_module('page', ['course' => $course->id]);
        $readycm = get_coursemodule_from_instance('page', $readypage->id, $course->id, false, MUST_EXIST);
        $pendingcm = get_coursemodule_from_instance('page', $pendingpage->id, $course->id, false, MUST_EXIST);

        $this->make_asset($course, $readycm, 0, 'en');
        $this->make_asset($course, $pendingcm, 0, 'en', asset_manager::STATUS_PENDING);

        $student = $gen->create_and_enrol($course, 'student');
        $items = download_manager::collect_for_course($course, (int)$student->id);

        $this->assertCount(1, $items);
        $this->assertSame((int)$readycm->id, $items[0]->cmid);
    }

    /**
     * Assets whose narration is disabled for the scope are excluded.
     *
     * @covers ::collect_for_course
     */
    public function test_collect_excludes_disabled_scope(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->make_asset($course, $cm, 0, 'en');

        override_manager::set((int)$course->id, (int)$cm->id, 0, false);

        $student = $gen->create_and_enrol($course, 'student');
        $items = download_manager::collect_for_course($course, (int)$student->id);
        $this->assertCount(0, $items);
    }

    /**
     * Languages outside the enabled allowlist are excluded.
     *
     * @covers ::collect_for_course
     */
    public function test_collect_excludes_unlisted_language(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->make_asset($course, $cm, 0, 'en');
        $this->make_asset($course, $cm, 0, 'de');

        $student = $gen->create_and_enrol($course, 'student');
        $items = download_manager::collect_for_course($course, (int)$student->id);

        $this->assertCount(1, $items);
        $this->assertSame('en', $items[0]->lang);
    }

    /**
     * Hidden Book chapters are withheld from students but offered to teachers
     * who hold mod/book:viewhiddenchapters.
     *
     * @covers ::collect_for_course
     */
    public function test_collect_gates_hidden_book_chapters(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $visible = $bookgen->create_chapter(['bookid' => $book->id]);
        $hidden = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);
        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);

        $this->make_asset($course, $cm, (int)$visible->id, 'en');
        $this->make_asset($course, $cm, (int)$hidden->id, 'en');

        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');

        $studentitems = download_manager::collect_for_course($course, (int)$student->id);
        $this->assertCount(1, $studentitems);
        $this->assertSame((int)$visible->id, $studentitems[0]->chapterid);

        $teacheritems = download_manager::collect_for_course($course, (int)$teacher->id);
        $this->assertCount(2, $teacheritems);
    }

    /**
     * The site-wide allow_downloads=0 kill switch yields nothing.
     *
     * @covers ::collect_for_course
     * @covers ::downloads_enabled
     */
    public function test_collect_respects_site_download_toggle(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->make_asset($course, $cm, 0, 'en');
        $student = $gen->create_and_enrol($course, 'student');

        $this->assertTrue(download_manager::downloads_enabled());
        $this->assertCount(1, download_manager::collect_for_course($course, (int)$student->id));

        set_config('allow_downloads', 0, 'local_aireader');
        $this->assertFalse(download_manager::downloads_enabled());
        $this->assertCount(0, download_manager::collect_for_course($course, (int)$student->id));
    }

    /**
     * total_bytes sums the collected sizes; warn_threshold_bytes converts MB
     * to bytes and treats non-positive values as "no threshold".
     *
     * @covers ::total_bytes
     * @covers ::warn_threshold_bytes
     */
    public function test_size_helpers(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->make_asset($course, $cm, 0, 'en', asset_manager::STATUS_READY, 2048);
        $this->make_asset($course, $cm, 0, 'es', asset_manager::STATUS_READY, 3072);

        $student = $gen->create_and_enrol($course, 'student');
        $items = download_manager::collect_for_course($course, (int)$student->id);
        $this->assertSame(5120, download_manager::total_bytes($items));

        set_config('download_warn_threshold_mb', 100, 'local_aireader');
        $this->assertSame(100 * 1024 * 1024, download_manager::warn_threshold_bytes());

        set_config('download_warn_threshold_mb', 0, 'local_aireader');
        $this->assertSame(0, download_manager::warn_threshold_bytes());
    }
}
