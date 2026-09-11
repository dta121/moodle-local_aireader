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
 * How long an asset waits before a page view may re-queue failed work.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Exponential cool-down between automatic re-queues of a failing asset.
 *
 * Dropping a task row the moment a failure is judged permanent is right for
 * recoverability, a zero-attempt row blocks every later re-queue for that
 * asset, but it removes the only thing that was rate-limiting the work.
 * `get_status` re-queues any asset sitting in pending/stale/error on every
 * call, so without a cool-down a deterministic failure (a translation model
 * the key cannot use, audio Whisper returns no segments for) would be
 * re-queued, run, fail and be re-queued again once per cron cycle for as long
 * as anyone keeps opening the page. On a busy course that is thousands of
 * billed API calls a day and an ad hoc queue that never settles.
 *
 * So the asset carries its own backoff. Each consecutive failure pushes the
 * next automatic attempt further out, and a success clears it. The schedule is
 * deliberately slower than core's task backoff (which starts at 60s): by the
 * time we are here the task-level retries are already spent, and what is left
 * is a failure that has survived them.
 *
 * An admin pressing Regenerate bypasses this entirely, that is a human
 * deciding to spend the money, and it is self-limiting.
 *
 * @package local_aireader
 */
class retry_backoff {
    /** @var int Wait after the first failure (1 hour). */
    public const BASE_SECONDS = 3600;

    /** @var int Ceiling on the wait (24 hours), so a broken asset costs ~1 run/day. */
    public const MAX_SECONDS = 86400;

    /**
     * Seconds to wait before the next automatic attempt.
     *
     * Doubles per consecutive failure and then flattens: 1h, 2h, 4h, 8h, 16h,
     * 24h, 24h, ... Anything at or below zero failures means "no wait", which
     * is what a freshly created or freshly succeeded asset should get.
     *
     * @param int $failcount Consecutive failures recorded against the asset,
     *                       including the one that just happened.
     * @return int Seconds to wait. 0 when there is nothing to wait for.
     */
    public static function delay_for(int $failcount): int {
        if ($failcount <= 0) {
            return 0;
        }
        // Cap the exponent before shifting so a large failcount cannot
        // overflow into a negative or absurd delay.
        $steps = min($failcount - 1, 16);
        return (int)min(self::BASE_SECONDS * (2 ** $steps), self::MAX_SECONDS);
    }

    /**
     * The timestamp to store as "do not re-queue automatically before this".
     *
     * @param int $failcount Consecutive failures including the current one.
     * @param int|null $now Current time, for tests. Defaults to time().
     * @return int UNIX timestamp.
     */
    public static function next_attempt_time(int $failcount, ?int $now = null): int {
        $now = $now ?? time();
        return $now + self::delay_for($failcount);
    }

    /**
     * Whether an automatic re-queue is allowed yet.
     *
     * @param int|null $retryafter Stored cool-down expiry, or null when none.
     * @param int|null $now Current time, for tests. Defaults to time().
     * @return bool True when the caller may queue work.
     */
    public static function may_retry(?int $retryafter, ?int $now = null): bool {
        if ($retryafter === null || $retryafter <= 0) {
            return true;
        }
        return ($now ?? time()) >= $retryafter;
    }
}
