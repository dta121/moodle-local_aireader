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
 * Unit tests for the retry-or-give-up rule shared by the narration tasks.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\exception\api_http_error;
use local_aireader\exception\tts_input_too_long;

/**
 * Tests for {@see failure_policy}.
 *
 * This is the decision that stops zero-attempt task rows being created. Get it
 * wrong in one direction and recoverable work is abandoned; wrong in the other
 * and a dead row blocks regeneration of that narration for four weeks.
 *
 * @coversDefaultClass \local_aireader\task\failure_policy
 */
final class failure_policy_test extends \advanced_testcase {
    /**
     * A transient failure with attempts to spare is rethrown so cron retries.
     *
     * @covers ::is_terminal
     */
    public function test_transient_failure_with_attempts_left_is_retried(): void {
        $this->assertFalse(failure_policy::is_terminal(new api_http_error('error_tts_http', 429), 12));
        $this->assertFalse(failure_policy::is_terminal(new api_http_error('error_tts_http', 503), 2));
        $this->assertFalse(failure_policy::is_terminal(new api_http_error('error_tts_http', 0), 5));
    }

    /**
     * A request the endpoint will reject identically forever is given up on
     * immediately, without spending the remaining attempts or the money.
     *
     * @covers ::is_terminal
     */
    public function test_request_level_failure_is_terminal_at_full_attempts(): void {
        foreach ([400, 401, 403, 404, 413, 422] as $status) {
            $this->assertTrue(
                failure_policy::is_terminal(new api_http_error('error_tts_http', $status), 12),
                "status {$status}"
            );
        }
    }

    /**
     * The case an earlier draft of this fix missed: a deterministic failure that arrives as a plain
     * moodle_exception rather than as api_http_error. It still gets its full
     * retry budget, but the last attempt exits cleanly instead of leaving a row
     * at zero attempts.
     *
     * @covers ::is_terminal
     */
    public function test_untyped_failure_retries_then_gives_up_on_the_last_attempt(): void {
        $e = new \moodle_exception('error_alignment_empty_response', 'local_aireader');

        $this->assertFalse(failure_policy::is_terminal($e, 12));
        $this->assertFalse(failure_policy::is_terminal($e, 2));
        $this->assertTrue(failure_policy::is_terminal($e, 1));
    }

    /**
     * The guard holds for anything thrown, not just moodle_exception, so an
     * unanticipated error can never manufacture a dead row either.
     *
     * @covers ::is_terminal
     */
    public function test_any_throwable_is_terminal_on_the_last_attempt(): void {
        $this->assertTrue(failure_policy::is_terminal(new \RuntimeException('boom'), 1));
        $this->assertTrue(failure_policy::is_terminal(new \TypeError('boom'), 1));
        $this->assertFalse(failure_policy::is_terminal(new \RuntimeException('boom'), 3));
    }

    /**
     * Zero already means the row is dead, so nothing is ever rethrown from
     * there. This is what makes "Run now" on an existing corpse a guaranteed
     * row-clearing operation rather than a gamble.
     *
     * @covers ::is_terminal
     */
    public function test_an_already_exhausted_row_never_rethrows(): void {
        $this->assertTrue(failure_policy::is_terminal(new api_http_error('error_tts_http', 429), 0));
        $this->assertTrue(failure_policy::is_terminal(new \moodle_exception('error_tts_empty', 'local_aireader'), 0));
    }

    /**
     * The over-length TTS rejection survives the widened rule: it is a 400, so
     * it stays terminal at full attempts rather than being retried eleven more
     * times at full price.
     *
     * @covers ::is_terminal
     */
    public function test_unsplittable_tts_input_stays_terminal(): void {
        $e = new tts_input_too_long('over the maximum input limit');

        $this->assertTrue(failure_policy::is_terminal($e, 12));
    }
}
