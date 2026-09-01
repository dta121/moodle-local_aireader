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
 * Unit tests for classifying API failures as retryable or permanent.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\exception;

/**
 * Tests for {@see api_http_error} and {@see tts_input_too_long}.
 *
 * This classification decides whether a failed adhoc task goes back into the
 * queue. Getting it wrong either loses recoverable work or recreates the
 * permanent-retry pile-up these classes exist to stop.
 *
 * @coversDefaultClass \local_aireader\exception\api_http_error
 */
final class api_http_error_test extends \advanced_testcase {
    /**
     * Statuses worth sending again.
     *
     * @return array
     */
    public static function retryable_provider(): array {
        return [
            'never completed' => [0],
            'request timeout' => [408],
            'conflict' => [409],
            'too early' => [425],
            'rate limited' => [429],
            'server error' => [500],
            'bad gateway' => [502],
            'unavailable' => [503],
            'gateway timeout' => [504],
        ];
    }

    /**
     * Statuses an identical retry cannot fix.
     *
     * @return array
     */
    public static function permanent_provider(): array {
        return [
            'bad request' => [400],
            'unauthorised' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'payload too large' => [413],
            'unsupported media type' => [415],
            'unprocessable' => [422],
        ];
    }

    /**
     * Transient statuses are retried.
     *
     * @dataProvider retryable_provider
     * @param int $status HTTP status.
     * @covers ::retryable
     */
    public function test_transient_statuses_are_retried(int $status): void {
        $this->assertTrue(api_http_error::retryable($status));
    }

    /**
     * Request-level failures are permanent.
     *
     * @dataProvider permanent_provider
     * @param int $status HTTP status.
     * @covers ::retryable
     */
    public function test_request_failures_are_permanent(int $status): void {
        $this->assertFalse(api_http_error::retryable($status));
    }

    /**
     * Both statuses from the incident this fixes are classed permanent.
     *
     * The TTS 400 and the Whisper 413 were rethrown and retried daily forever.
     *
     * @covers ::retryable
     */
    public function test_the_statuses_that_caused_the_pileup_are_permanent(): void {
        $this->assertFalse(api_http_error::retryable(400));
        $this->assertFalse(api_http_error::retryable(413));
    }

    /**
     * The status travels on the exception, and existing catch blocks still match.
     *
     * @covers ::__construct
     */
    public function test_status_is_carried_and_remains_a_moodle_exception(): void {
        $e = new api_http_error('error_alignment_http', 413, 'Maximum content size limit exceeded');

        $this->assertSame(413, $e->status);
        $this->assertInstanceOf(\moodle_exception::class, $e);
    }

    /**
     * The over-length rejection is a 400 and is recognised only from a 400.
     *
     * @covers \local_aireader\exception\tts_input_too_long::matches
     */
    public function test_over_length_rejection_is_recognised(): void {
        $message = 'Input of 2159 tokens is over the maximum input limit of 2000 tokens.';

        $this->assertTrue(tts_input_too_long::matches(400, $message));
        $this->assertFalse(tts_input_too_long::matches(400, 'Invalid value for voice: nope'));
        $this->assertFalse(tts_input_too_long::matches(429, $message));
        $this->assertFalse(tts_input_too_long::matches(413, 'Maximum content size limit exceeded'));
    }

    /**
     * A chunk that survives splitting ends up permanent, so it leaves the queue.
     *
     * @covers \local_aireader\exception\tts_input_too_long::__construct
     */
    public function test_unsplittable_chunk_is_permanent(): void {
        $e = new tts_input_too_long('over the maximum input limit');

        $this->assertSame(400, $e->status);
        $this->assertInstanceOf(api_http_error::class, $e);
        $this->assertFalse(api_http_error::retryable($e->status));
    }
}
