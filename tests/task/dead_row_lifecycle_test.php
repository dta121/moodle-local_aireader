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
 * End-to-end reproduction of the production "Next run: Never" symptom.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use core\task\manager;

/**
 * Drives Moodle's real adhoc task manager, not a stub.
 *
 * learn.saylor.org and degrees.saylor.org each show 3 failed generate_audio and
 * 3 failed align_audio tasks whose next run is "Never". Every other test in this
 * plugin checks the plugin's own classification logic in isolation, which cannot
 * show what core does with a task that keeps throwing. These tests queue real
 * tasks through core and fail them the way cron does, so the symptom is
 * reproduced rather than assumed.
 *
 * @coversNothing
 */
final class dead_row_lifecycle_test extends \advanced_testcase {
    /**
     * Queue a generate_audio task the way asset_manager does.
     *
     * @param int $assetid Asset the task is for.
     * @return \local_aireader\task\generate_audio The queued task, reloaded from the queue.
     */
    private function queue(int $assetid): generate_audio {
        $task = new generate_audio();
        $task->set_custom_data(['assetid' => $assetid]);
        manager::queue_adhoc_task($task, true);
        return $this->reload($assetid);
    }

    /**
     * Read a queued generate_audio task back out of the database.
     *
     * @param int $assetid Asset the task is for.
     * @return \local_aireader\task\generate_audio|null
     */
    private function reload(int $assetid): ?generate_audio {
        global $DB;
        $records = $DB->get_records(
            'task_adhoc',
            ['component' => 'local_aireader',
            'classname' => '\\' . generate_audio::class]
        );
        foreach ($records as $record) {
            $task = manager::adhoc_task_from_record($record);
            $data = $task->get_custom_data();
            if ((int)($data->assetid ?? 0) === $assetid) {
                return $task;
            }
        }
        return null;
    }

    /**
     * Fail the queued task the way cron does: acquire it through
     * get_next_adhoc_task (which locks it) and then report the failure.
     *
     * Calling adhoc_task_failed on an unacquired task is not a valid sequence:
     * core releases the lock that only the acquisition grants.
     *
     * @param int $assetid Asset whose task should be failed.
     * @param int $when Clock time to acquire at; must be past the growing backoff.
     * @return bool True if a task was acquired and failed.
     */
    private function fail_once(int $assetid, int $when): bool {
        $task = manager::get_next_adhoc_task($when);
        if ($task === null) {
            return false;
        }
        $this->assertInstanceOf(generate_audio::class, $task);
        $data = $task->get_custom_data();
        $this->assertSame($assetid, (int)$data->assetid);
        manager::adhoc_task_failed($task, false);
        return true;
    }

    /**
     * Exhaust every attempt on the queued task, as ~34 hours of cron would.
     *
     * @param int $assetid Asset whose task should be exhausted.
     * @return int Number of failures applied.
     */
    private function exhaust(int $assetid): int {
        $n = 0;
        // Each failure doubles the backoff from 60s, capped at 86400, so stepping
        // the clock a day per attempt always clears it.
        while ($n < 20 && $this->fail_once($assetid, time() + ($n + 1) * DAYSECS)) {
            $n++;
        }
        return $n;
    }

    /**
     * The production symptom, reproduced: a task that keeps throwing consumes
     * every attempt and then becomes invisible to cron forever.
     */
    public function test_repeated_failure_produces_a_permanently_dead_row(): void {
        global $DB;
        $this->resetAfterTest();

        $task = $this->queue(4871);
        $this->assertSame(
            12,
            $task->get_attempts_available(),
            'Core starts an adhoc task at 12 attempts.'
        );

        // Fail it the way cron does when execute() throws.
        $attempts = $this->exhaust(4871);
        $task = $this->reload(4871);

        $this->assertSame(
            12,
            $attempts,
            'It takes exactly 12 failures to exhaust the attempts.'
        );
        $this->assertNotNull($task, 'The row still exists, it is just unrunnable.');
        $this->assertSame(0, $task->get_attempts_available());

        // This is what the admin screen renders as "Next run: Never"
        // (admin/tool/task/renderer.php prints it when attempts hit zero).
        $this->assertSame(0, $task->get_attempts_available());

        // And this is why it never recovers on its own.
        $this->assertNull(
            manager::get_next_adhoc_task(time() + YEARSECS),
            'Cron must never hand out a task with no attempts left.'
        );

        $this->assertTrue(
            $DB->record_exists('task_adhoc', ['id' => $task->get_id()]),
            'The dead row stays in the table, which is why it shows in the failed list.'
        );
    }

    /**
     * The amplifier: a dead row silently blocks every later re-queue for that
     * asset, so "Regenerate" and learner page views quietly do nothing.
     *
     * On Moodle 4.5 and 5.0, which is where the production symptom was seen.
     * Core 5.1 changed the duplicate check to skip rows with no attempts left,
     * so there the dead row is inert clutter rather than a blocker, and the
     * same sequence produces a fresh row. Both behaviours are pinned so a
     * change in either direction is noticed.
     */
    public function test_a_dead_row_blocks_requeueing_the_same_asset(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $this->queue(4871);
        $this->assertSame(12, $this->exhaust(4871));
        $task = $this->reload(4871);
        $this->assertSame(0, $task->get_attempts_available());

        $before = $DB->count_records('task_adhoc', ['component' => 'local_aireader']);

        // Exactly what asset_manager::queue_generation() does.
        $again = new generate_audio();
        $again->set_custom_data(['assetid' => 4871]);
        manager::queue_adhoc_task($again, true);

        $blocks = (int)$CFG->branch < 501;
        $this->assertSame(
            $blocks ? $before : $before + 1,
            $DB->count_records('task_adhoc', ['component' => 'local_aireader']),
            $blocks
                ? 'The duplicate check matches the dead row and drops the new task on the '
                    . 'floor, so the asset can never be regenerated while it sits there.'
                : 'From 5.1 core ignores exhausted rows in the duplicate check, so a new '
                    . 'task is queued alongside the dead one.'
        );

        // A different asset is unaffected, so any block is per asset.
        $expected = $DB->count_records('task_adhoc', ['component' => 'local_aireader']) + 1;
        $other = new generate_audio();
        $other->set_custom_data(['assetid' => 9999]);
        manager::queue_adhoc_task($other, true);
        $this->assertSame(
            $expected,
            $DB->count_records('task_adhoc', ['component' => 'local_aireader'])
        );
    }

    /**
     * Clearing the dead row restores the ability to queue that asset again,
     * which is the whole point of the recovery path.
     */
    public function test_clearing_a_dead_row_unblocks_the_asset(): void {
        global $DB;
        $this->resetAfterTest();

        $this->queue(4871);
        $this->exhaust(4871);
        $task = $this->reload(4871);

        $found = dead_task_cleaner::find_dead();
        $this->assertNotEmpty($found, 'The cleaner must see the dead row.');

        $dry = dead_task_cleaner::clear(true);
        $this->assertNotEmpty($dry);
        $this->assertTrue(
            $DB->record_exists('task_adhoc', ['id' => $task->get_id()]),
            'A dry run must not delete anything.'
        );

        dead_task_cleaner::clear(false);
        $this->assertFalse(
            $DB->record_exists('task_adhoc', ['id' => $task->get_id()]),
            'The real run removes the dead row.'
        );

        // The asset can now be queued again.
        $again = new generate_audio();
        $again->set_custom_data(['assetid' => 4871]);
        manager::queue_adhoc_task($again, true);
        $this->assertNotNull(
            $this->reload(4871),
            'With the corpse gone, regeneration works again.'
        );
    }

    /**
     * A healthy task is untouched by the cleaner, so recovery cannot eat live work.
     */
    public function test_the_cleaner_leaves_runnable_tasks_alone(): void {
        global $DB;
        $this->resetAfterTest();

        $this->queue(4871);
        $before = $DB->count_records('task_adhoc', ['component' => 'local_aireader']);

        dead_task_cleaner::clear(false);

        $this->assertSame(
            $before,
            $DB->count_records('task_adhoc', ['component' => 'local_aireader']),
            'A task with attempts remaining must survive.'
        );
    }

    /**
     * The cleaner must never touch another plugin's failed tasks.
     */
    public function test_the_cleaner_only_touches_this_plugin(): void {
        global $DB;
        $this->resetAfterTest();

        $foreign = (object)[
            'component' => 'local_somethingelse',
            'classname' => '\\local_somethingelse\\task\\whatever',
            'nextruntime' => time(),
            'faildelay' => 86400,
            'attemptsavailable' => 0,
            'timecreated' => time(),
        ];
        $foreign->id = $DB->insert_record('task_adhoc', $foreign);

        dead_task_cleaner::clear(false);

        $this->assertTrue(
            $DB->record_exists('task_adhoc', ['id' => $foreign->id]),
            'Another component\'s dead task is none of our business.'
        );
    }
}
