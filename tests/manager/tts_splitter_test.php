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
 * Unit tests for the split-and-retry TTS path.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

use local_aireader\exception\tts_input_too_long;

/**
 * Tests for {@see tts_splitter}.
 *
 * The synthesiser is a closure, so the retry logic is exercised without any
 * HTTP, standing in for an endpoint that rejects over-long input.
 *
 * @coversDefaultClass \local_aireader\manager\tts_splitter
 */
final class tts_splitter_test extends \advanced_testcase {
    /** @var array Text of every piece the fake endpoint was asked to synthesise. */
    private $calls = [];

    /**
     * A fake endpoint that rejects anything longer than $limit characters.
     *
     * @param int $limit Character limit to enforce.
     * @return callable Synthesiser returning the piece wrapped in brackets.
     */
    private function endpoint(int $limit): callable {
        $this->calls = [];
        return function (string $piece) use ($limit): string {
            $this->calls[] = $piece;
            if (mb_strlen($piece) > $limit) {
                throw new tts_input_too_long(
                    'Input of 2159 tokens is over the maximum input limit of 2000 tokens.');
            }
            return '[' . $piece . ']';
        };
    }

    /**
     * Sample narration text of roughly $sentences sentences.
     *
     * @param int $sentences Number of sentences.
     * @return string
     */
    private function narration(int $sentences): string {
        return trim(str_repeat('The quick brown fox jumped over the lazy dog and kept running. ', $sentences));
    }

    /**
     * Text that fits is sent once, with no splitting.
     *
     * @covers ::synthesize_split
     */
    public function test_text_that_fits_is_sent_once(): void {
        $synth = $this->endpoint(500);

        $audio = tts_splitter::synthesize_split($synth, 'Short line.', 2400);

        $this->assertSame('[Short line.]', $audio);
        $this->assertCount(1, $this->calls);
    }

    /**
     * A rejected chunk is subdivided until every piece is accepted.
     *
     * @covers ::synthesize_split
     */
    public function test_rejected_text_is_split_until_accepted(): void {
        $synth = $this->endpoint(500);
        $text = $this->narration(40);

        tts_splitter::synthesize_split($synth, $text, 2400);

        $accepted = array_filter($this->calls, fn($c) => mb_strlen($c) <= 500);
        $this->assertGreaterThan(1, count($accepted));
        $this->assertLessThanOrEqual(500, max(array_map('mb_strlen', $accepted)));
    }

    /**
     * Splitting must not lose, duplicate or reorder any word.
     *
     * This is the property that matters most: a narration that silently drops a
     * paragraph is worse than one that fails loudly.
     *
     * @covers ::synthesize_split
     */
    public function test_no_content_is_lost_or_reordered(): void {
        $synth = $this->endpoint(500);
        $text = $this->narration(40);

        $audio = tts_splitter::synthesize_split($synth, $text, 2400);

        $before = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $after = preg_split('/\s+/', str_replace(['[', ']'], ' ', $audio), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertSame($before, $after);
    }

    /**
     * Audio pieces are concatenated in reading order.
     *
     * @covers ::synthesize_split
     */
    public function test_audio_is_concatenated_in_order(): void {
        $synth = $this->endpoint(500);

        $audio = tts_splitter::synthesize_split($synth, $this->narration(40), 2400);

        $accepted = array_values(array_filter($this->calls, fn($c) => mb_strlen($c) <= 500));
        $this->assertSame('[' . implode('][', $accepted) . ']', $audio);
    }

    /**
     * An endpoint that always rejects gives up rather than retrying forever.
     *
     * @covers ::synthesize_split
     */
    public function test_it_gives_up_instead_of_spinning(): void {
        $this->calls = [];
        $always = function (string $piece): string {
            $this->calls[] = $piece;
            throw new tts_input_too_long('over the maximum input limit');
        };

        try {
            tts_splitter::synthesize_split($always, $this->narration(40), 2400);
            $this->fail('Expected tts_input_too_long to be rethrown.');
        } catch (tts_input_too_long $e) {
            $this->assertLessThan(200, count($this->calls));
        }
    }

    /**
     * Text already below the minimum piece size is not subdivided at all.
     *
     * Below that size the rejection is not about length, so splitting would
     * only spend money to fail identically.
     *
     * @covers ::synthesize_split
     */
    public function test_text_below_the_minimum_is_not_subdivided(): void {
        $this->calls = [];
        $always = function (string $piece): string {
            $this->calls[] = $piece;
            throw new tts_input_too_long('over the maximum input limit');
        };

        try {
            tts_splitter::synthesize_split($always, 'Tiny.', 2400);
            $this->fail('Expected tts_input_too_long to be rethrown.');
        } catch (tts_input_too_long $e) {
            $this->assertCount(1, $this->calls);
        }
    }

    /**
     * A failure that is not an over-length rejection is never retried.
     *
     * @covers ::synthesize_split
     */
    public function test_other_failures_are_not_retried(): void {
        $this->calls = [];
        $broken = function (string $piece): string {
            $this->calls[] = $piece;
            throw new \moodle_exception('error_tts_http', 'local_aireader', '', 'HTTP 400: Invalid voice');
        };

        try {
            tts_splitter::synthesize_split($broken, $this->narration(40), 2400);
            $this->fail('Expected the generic failure to propagate.');
        } catch (\moodle_exception $e) {
            $this->assertCount(1, $this->calls);
            $this->assertNotInstanceOf(tts_input_too_long::class, $e);
        }
    }
}
