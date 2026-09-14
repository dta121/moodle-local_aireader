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
 * Unit tests for the per-asset re-queue cool-down.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see retry_backoff}.
 *
 * This is the spend limit. Once a failing task row is deleted rather than left
 * at zero attempts, nothing else stops `get_status` re-queueing the same doomed
 * work on every page view, so the numbers here are the difference between one
 * billed API call a day for a broken asset and one per cron cycle.
 *
 * @coversDefaultClass \local_aireader\manager\retry_backoff
 */
final class retry_backoff_test extends \advanced_testcase {
    /**
     * The published schedule: an hour, then doubling.
     *
     * @covers ::delay_for
     */
    public function test_the_delay_doubles_per_consecutive_failure(): void {
        $this->assertSame(3600, retry_backoff::delay_for(1));
        $this->assertSame(7200, retry_backoff::delay_for(2));
        $this->assertSame(14400, retry_backoff::delay_for(3));
        $this->assertSame(28800, retry_backoff::delay_for(4));
        $this->assertSame(57600, retry_backoff::delay_for(5));
    }

    /**
     * It flattens at a day, so an asset nobody ever fixes still gets looked at
     * occasionally instead of being abandoned outright.
     *
     * @covers ::delay_for
     */
    public function test_the_delay_is_capped_at_a_day(): void {
        $this->assertSame(86400, retry_backoff::delay_for(6));
        $this->assertSame(86400, retry_backoff::delay_for(20));
        // Far past the point where doubling would overflow into nonsense.
        $this->assertSame(86400, retry_backoff::delay_for(PHP_INT_MAX));
    }

    /**
     * No failures means no wait. A fresh asset, and one that has just
     * succeeded, must not be held back by anything.
     *
     * @covers ::delay_for
     */
    public function test_no_failures_means_no_delay(): void {
        $this->assertSame(0, retry_backoff::delay_for(0));
        $this->assertSame(0, retry_backoff::delay_for(-1));
    }

    /**
     * The stored timestamp is just now plus the delay.
     *
     * @covers ::next_attempt_time
     */
    public function test_next_attempt_time_is_now_plus_the_delay(): void {
        $this->assertSame(1000 + 3600, retry_backoff::next_attempt_time(1, 1000));
        $this->assertSame(1000 + 86400, retry_backoff::next_attempt_time(9, 1000));
        $this->assertSame(1000, retry_backoff::next_attempt_time(0, 1000));
    }

    /**
     * An absent cool-down never blocks. Assets created before this release
     * carry null here, and they must behave exactly as they did.
     *
     * @covers ::may_retry
     */
    public function test_an_absent_cooldown_allows_the_retry(): void {
        $this->assertTrue(retry_backoff::may_retry(null, 1000));
        $this->assertTrue(retry_backoff::may_retry(0, 1000));
    }

    /**
     * The boundary is inclusive: at the stored second the wait is over.
     *
     * @covers ::may_retry
     */
    public function test_the_cooldown_expires_at_the_stored_second(): void {
        $this->assertFalse(retry_backoff::may_retry(1000, 999));
        $this->assertTrue(retry_backoff::may_retry(1000, 1000));
        $this->assertTrue(retry_backoff::may_retry(1000, 1001));
    }
}
