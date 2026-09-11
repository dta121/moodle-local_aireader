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
 * Scheduled sweep that removes narration task rows which have given up.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use core\task\scheduled_task;

/**
 * Hourly sweep for `local_aireader` ad hoc rows at zero remaining attempts.
 *
 * {@see failure_policy} stops the tasks creating these rows when a failure
 * arrives as a catchable exception, which is most of them. It cannot stop the
 * rest. A run that dies without unwinding, PHP fatal, `memory_limit`, OOM
 * killer, worker restart, never enters the plugin's catch block at all; core's
 * `task_lock_cleanup_task` picks up the abandoned lock and fails the task
 * itself, decrementing `attemptsavailable` with no plugin code involved. Twelve
 * of those and the row is at zero.
 *
 * A zero-attempt row is not inert. `get_next_adhoc_task()` skips it so it never
 * runs again, but `get_queued_adhoc_task_record()` still matches it by
 * classname, component and customdata, so it suppresses every later re-queue
 * for the same asset. Left alone it does that until core's four-week failed-task
 * purge removes it.
 *
 * This sweep closes the window to an hour, for every failure path including the
 * ones that never raise a Throwable. It is the same operation the recovery CLI
 * performs, run on a schedule.
 *
 * @package local_aireader
 */
class reap_dead_tasks extends scheduled_task {
    /**
     * Human-readable task name shown in the scheduled-tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reap_dead_tasks', 'local_aireader');
    }

    /**
     * Delete this plugin's exhausted ad hoc rows and say what went.
     */
    public function execute() {
        $dead = dead_task_cleaner::clear(false);
        if (!$dead) {
            return;
        }

        // Worth a line each: after this release these should be rare, and each
        // one is evidence of a failure path that never reached a catch block.
        foreach ($dead as $row) {
            mtrace("local_aireader: removed exhausted task {$row->id} ({$row->classname})"
                . ($row->assetid > 0 ? " for asset {$row->assetid}" : ' with no assetid'));
        }
        mtrace('local_aireader: ' . count($dead) . ' exhausted task row(s) removed. '
            . 'These were created by a failure that never reached the task\'s catch block '
            . '(a PHP fatal or memory exhaustion mid-run is the usual cause).');
    }
}
