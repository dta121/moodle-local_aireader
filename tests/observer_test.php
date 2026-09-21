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
 * Tests that saving unreachable content does not queue generation.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader;

use local_aireader\manager\asset_manager;

/**
 * Tests for {@see observer}.
 *
 * On learn.saylor.org the save path had produced 4,866 assets (8% of all
 * assets, 18.2M characters of TTS and translation) against hidden activities
 * and retired course shells: an editor tidying an archived course fires the
 * same update event as one editing a live course, and nothing distinguished
 * them. These tests pin the distinction.
 *
 * @coversDefaultClass \local_aireader\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Build a course + page and return [courseid, cmid].
     *
     * @param array $courseopts Overrides for the course record.
     * @param int $cmvisible Whether the module itself is visible.
     * @return array{0:int,1:int}
     */
    private function make_page(array $courseopts = [], int $cmvisible = 1): array {
        $course = $this->getDataGenerator()->create_course($courseopts);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'visible' => $cmvisible]
        );
        return [(int)$course->id, (int)$page->cmid];
    }

    /**
     * Queue one asset row for a cm so there is something regeneration could pick up.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @return int Asset id.
     */
    private function seed_asset(int $courseid, int $cmid): int {
        global $DB;
        return (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid' => $courseid, 'cmid' => $cmid,
            'contextid' => \context_module::instance($cmid)->id,
            'module' => 'page', 'instanceid' => 1, 'chapterid' => 0,
            'lang' => 'en', 'voice' => 'marin', 'model' => 'gpt-4o-mini-tts',
            'sourcehash' => 'hash', 'status' => asset_manager::STATUS_READY,
            'failcount' => 0, 'alignfailcount' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Fire the observer for a cm by invoking the private guard path.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    private function fire(int $cmid): void {
        $m = new \ReflectionMethod(observer::class, 'invalidate_for_cm');
        $m->setAccessible(true);
        $m->invoke(null, $cmid, 'page');
    }

    /**
     * Count queued generate_audio ad hoc tasks.
     *
     * queue_generation() creates an ad hoc task; it does not move the asset's
     * status, so the task queue -- not the status column -- is what says
     * whether synthesis was actually requested.
     *
     * @return int
     */
    private function queued_tasks(): int {
        global $DB;
        return $DB->count_records(
            'task_adhoc',
            ['classname' => '\\local_aireader\\task\\generate_audio']
        );
    }

    /**
     * A live, visible page still queues regeneration -- the guard must not
     * break the normal case it is wrapped around.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_visible_page_in_live_course_still_queues(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page();
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * A hidden activity queues nothing: no learner can open it.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_hidden_module_does_not_queue(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page([], 0);
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * A hidden course queues nothing, even when the activity inside is visible.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_hidden_course_does_not_queue(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page(['visible' => 0]);
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * A course whose end date has passed queues nothing.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_ended_course_does_not_queue(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page(['startdate' => time() - (60 * DAYSECS), 'enddate' => time() - DAYSECS]);
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * enddate 0 means open-ended, not expired. Most Saylor courses carry no
     * end date at all, so reading 0 as "in the past" would disable generation
     * for nearly the whole catalogue.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_course_with_no_end_date_still_queues(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page(['enddate' => 0]);
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * The stale mark still happens for unreachable content. If the activity is
     * unhidden later the cached audio must not be served as current.
     *
     * @covers ::invalidate_for_cm
     */
    public function test_hidden_content_is_still_marked_stale(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auto_generate_on_save', 1, 'local_aireader');
        [$courseid, $cmid] = $this->make_page([], 0);
        $assetid = $this->seed_asset($courseid, $cmid);

        $this->fire($cmid);

        $status = $DB->get_field('local_aireader_asset', 'status', ['id' => $assetid]);
        $this->assertSame(asset_manager::STATUS_STALE, $status);
    }
}
