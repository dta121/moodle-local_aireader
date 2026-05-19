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
 * Tests for the per-resource enable/disable override resolution chain.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see override_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\override_manager
 */
final class override_manager_test extends \advanced_testcase {
    /**
     * Without any override, resolution falls through to the plugin-level
     * `enable_page` / `enable_book` global setting.
     *
     * @covers ::is_enabled
     */
    public function test_falls_back_to_global_default(): void {
        $this->resetAfterTest();
        set_config('enable_page', 1, 'local_aireader');
        $this->assertTrue(override_manager::is_enabled(12345, 0, 'page'));
        set_config('enable_page', 0, 'local_aireader');
        $this->assertFalse(override_manager::is_enabled(12345, 0, 'page'));
    }

    /**
     * An activity-level override overrides the global default, and does not
     * leak to other course modules.
     *
     * @covers ::is_enabled
     * @covers ::set
     */
    public function test_activity_override_beats_global(): void {
        $this->resetAfterTest();
        set_config('enable_page', 1, 'local_aireader');
        override_manager::set(1, 42, 0, false);
        $this->assertFalse(override_manager::is_enabled(42, 0, 'page'));
        $this->assertTrue(override_manager::is_enabled(43, 0, 'page'));
    }

    /**
     * A chapter-level override for a book wins over the activity-level
     * override; other chapters keep falling back to the activity scope.
     *
     * @covers ::is_enabled
     * @covers ::set
     */
    public function test_chapter_override_beats_activity(): void {
        $this->resetAfterTest();
        set_config('enable_book', 1, 'local_aireader');
        override_manager::set(1, 42, 0, true);
        override_manager::set(1, 42, 7, false);
        $this->assertTrue(override_manager::is_enabled(42, 0, 'book'));
        $this->assertFalse(override_manager::is_enabled(42, 7, 'book'));
        $this->assertTrue(override_manager::is_enabled(42, 8, 'book'));
    }

    /**
     * `set` upserts: a second call for the same scope updates the row in
     * place rather than violating the (cmid, chapterid) unique index.
     *
     * @covers ::set
     * @covers ::get
     */
    public function test_set_upserts_rather_than_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        override_manager::set(1, 42, 0, true);
        override_manager::set(1, 42, 0, false);
        $count = $DB->count_records('local_aireader_override', ['cmid' => 42, 'chapterid' => 0]);
        $this->assertSame(1, $count);
        $this->assertFalse(override_manager::get(42, 0));
    }

    /**
     * Purging by cm removes both activity-level and chapter-level rows.
     *
     * @covers ::purge_cm
     */
    public function test_purge_cm_removes_all_scopes(): void {
        global $DB;
        $this->resetAfterTest();
        override_manager::set(1, 42, 0, false);
        override_manager::set(1, 42, 7, false);
        override_manager::set(1, 99, 0, false);
        override_manager::purge_cm(42);
        $this->assertSame(0, $DB->count_records('local_aireader_override', ['cmid' => 42]));
        $this->assertSame(1, $DB->count_records('local_aireader_override', ['cmid' => 99]));
    }
}
