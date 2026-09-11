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
 * Tests for the scheduled sweep of exhausted narration task rows.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

/**
 * Tests for {@see reap_dead_tasks}.
 *
 * This exists because {@see failure_policy} cannot cover every failure. A run
 * that dies without unwinding — PHP fatal, memory_limit, OOM killer, worker
 * restart — never enters the plugin's catch block; core's lock cleanup fails
 * the task itself and spends the attempt. Twelve of those still produce a
 * zero-attempt row, and that row blocks every later re-queue for its asset
 * until core's four-week purge. The sweep closes that window to an hour.
 *
 * @coversDefaultClass \local_aireader\task\reap_dead_tasks
 */
final class reap_dead_tasks_test extends \advanced_testcase {
    /**
     * The sweep removes a row no plugin code could have prevented: one that
     * reached zero attempts without any exception ever being caught.
     *
     * @covers ::execute
     */
    public function test_a_row_killed_outside_the_catch_block_is_removed(): void {
        global $DB;
        $this->resetAfterTest();

        $dead = $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0);
        $live = $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 7);

        $output = $this->run_task();

        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $dead]));
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $live]));
        $this->assertStringContainsString('exhausted task', $output);
    }

    /**
     * Other components' failures are not ours to clean up.
     *
     * @covers ::execute
     */
    public function test_other_components_are_left_alone(): void {
        global $DB;
        $this->resetAfterTest();

        $theirs = $this->insert_task('mod_foo\task\other', 'mod_foo', 0);

        $this->run_task();

        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $theirs]));
    }

    /**
     * Nothing to do means no output, so a healthy site does not log a line an
     * hour forever.
     *
     * @covers ::execute
     */
    public function test_a_clean_queue_produces_no_noise(): void {
        $this->resetAfterTest();

        $this->assertSame('', $this->run_task());
    }

    /**
     * The task is actually registered, or none of the above ever runs in
     * production.
     *
     * @covers ::get_name
     */
    public function test_the_task_is_registered_and_named(): void {
        $this->resetAfterTest();

        $tasks = \core\task\manager::load_scheduled_tasks_for_component('local_aireader');
        $classes = array_map(static function (\core\task\scheduled_task $task): string {
            return get_class($task);
        }, $tasks);

        $this->assertContains(reap_dead_tasks::class, $classes);
        $this->assertNotEmpty((new reap_dead_tasks())->get_name());
    }

    /**
     * Run the scheduled task with its output captured.
     *
     * @return string Everything the task traced.
     */
    private function run_task(): string {
        ob_start();
        try {
            (new reap_dead_tasks())->execute();
        } finally {
            $output = (string)ob_get_clean();
        }
        return $output;
    }

    /**
     * Insert a task_adhoc row directly: attemptsavailable = 0 is not reachable
     * through the public API.
     *
     * @param string $classname Fully qualified task class name.
     * @param string $component Owning component.
     * @param int|null $attempts Remaining attempts; null means unlimited.
     * @return int The inserted row id.
     */
    private function insert_task(string $classname, string $component, ?int $attempts): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('task_adhoc', (object)[
            'component'         => $component,
            'classname'         => $classname,
            'nextruntime'       => $now,
            'faildelay'         => 86400,
            'customdata'        => json_encode(['assetid' => 4242]),
            'timecreated'       => $now,
            'attemptsavailable' => $attempts,
            'firststartingtime' => $now - 3600,
        ]);
    }
}
