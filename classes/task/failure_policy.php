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
 * Decides whether a failed ad hoc task run should be rethrown or exited cleanly.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\exception\api_http_error;

/**
 * The one rule both narration tasks apply when a run fails.
 *
 * Rethrowing from `execute()` is how a task asks Moodle for another go, and it
 * costs one of the row's `attemptsavailable`. When that counter reaches zero
 * the row is not merely finished, it is toxic: `get_next_adhoc_task()` filters
 * on `attemptsavailable > 0`, so cron never touches it again, and
 * `get_queued_adhoc_task_record()` matches purely on classname, component and
 * customdata, so the dead row keeps matching every later re-queue for the same
 * asset. One exhausted row therefore blocks regeneration of that narration for
 * the four weeks until core's failed-task purge removes it.
 *
 * The v1.8.1 fix asked the wrong question. It tested the exception's *type*,
 * so only failures that happened to arrive as {@see api_http_error} could exit
 * cleanly, and the deterministic `moodle_exception` throw sites (a rejected
 * translation model, a transcription that comes back with no segments) still
 * burned every attempt and left a corpse. The question that actually matters
 * is "would rethrowing achieve anything?", and there are exactly two ways the
 * answer is no.
 *
 * Scope, stated precisely, because an earlier version of this file claimed
 * more than it delivers: this rule runs inside the task's `catch`, so it can
 * only act on a failure that reaches PHP as a {@see \Throwable}. It does
 * nothing for a run that dies without unwinding — a PHP fatal, the OOM killer,
 * `memory_limit`, a worker restart mid-run. In that case nothing in this
 * plugin executes at all: core's `task_lock_cleanup_task` reacquires the
 * abandoned lock and calls `manager::adhoc_task_failed()` itself, which
 * decrements `attemptsavailable` exactly as a rethrow would. Repeat that
 * twelve times and the row still reaches zero, and this class never saw it.
 *
 * That path is covered, but by a different mechanism:
 * {@see reap_dead_tasks} sweeps zero-attempt rows hourly whatever created
 * them, so a corpse blocks re-queueing for at most an hour rather than four
 * weeks. Between them the two cover every failure path; neither covers it
 * alone. If dead rows keep appearing after this release, the reaper's log line
 * is the signal, and memory exhaustion during synthesis is the first thing to
 * check — `generate_audio` holds the whole mp3 in a PHP string.
 *
 * @package local_aireader
 */
class failure_policy {
    /**
     * Whether this failure should end the run instead of being rethrown.
     *
     * Two cases, either of which means a retry is pointless:
     *
     * 1. This is the last attempt. `attemptsavailable` is loaded onto the task
     *    before `execute()` runs and only decremented afterwards, so a value of
     *    1 inside the catch block means rethrowing now is what creates the dead
     *    row. Giving up one attempt early costs nothing and avoids that.
     * 2. The endpoint rejected the request itself. A 400/401/403/404/413 says
     *    something about the request that an identical retry cannot change, so
     *    there is no point spending the remaining eleven attempts (and eleven
     *    more billed calls) discovering that.
     *
     * Callers are expected to have already recorded the error against the asset,
     * so exiting cleanly loses no diagnostics; it only stops the retry.
     *
     * @param \Throwable $e The failure caught by the task.
     * @param int $attemptsavailable Attempts left on the row, from
     *                               {@see \core\task\adhoc_task::get_attempts_available()}.
     * @return bool True to return from execute(), false to rethrow and retry.
     */
    public static function is_terminal(\Throwable $e, int $attemptsavailable): bool {
        if ($attemptsavailable <= 1) {
            return true;
        }
        return $e instanceof api_http_error && !api_http_error::retryable((int)$e->status);
    }
}
