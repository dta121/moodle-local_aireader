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
 * Tests for the OpenAI speech client helpers.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see openai_client}.
 *
 * @coversDefaultClass \local_aireader\manager\openai_client
 */
final class openai_client_test extends \advanced_testcase {
    /**
     * Invalid chunk sizes must fall back instead of looping forever.
     *
     * @covers ::chunk_text
     */
    public function test_chunk_text_handles_non_positive_chunk_size(): void {
        $text = 'Short text for narration.';

        $this->assertSame([$text], openai_client::chunk_text($text, 0));
        $this->assertSame([$text], openai_client::chunk_text($text, -10));
    }

    /**
     * Long unbroken text is split without producing empty chunks.
     *
     * @covers ::chunk_text
     */
    public function test_chunk_text_does_not_emit_empty_chunks(): void {
        $chunks = openai_client::chunk_text('abcdefghij', 3);

        $this->assertSame(['abc', 'def', 'ghi', 'j'], $chunks);
    }

    /**
     * CJK text well under the character cap can still blow the token cap; every
     * chunk must respect both ceilings. Regression for the gpt-4o-mini-tts
     * "over the maximum input limit of 2000 tokens" failure.
     *
     * @covers ::chunk_text
     * @covers ::estimate_tokens
     */
    public function test_chunk_text_respects_token_cap_for_cjk(): void {
        // ~4800 wide chars: under the 3800 char cap only after splitting, and
        // far over a single chunk's token budget.
        $text = str_repeat('これはテストの文章です。', 400);
        $maxtokens = 1800;

        $chunks = openai_client::chunk_text($text, 3800, $maxtokens);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk));
            $this->assertLessThanOrEqual($maxtokens, openai_client::estimate_tokens($chunk));
            $this->assertLessThanOrEqual(3800, mb_strlen($chunk));
        }
        // No characters are lost (whitespace at join boundaries aside).
        $strip = static fn(string $s): string => preg_replace('/\s+/u', '', $s);
        $this->assertSame($strip($text), $strip(implode('', $chunks)));
    }

    /**
     * Token estimation counts wide (CJK) characters near 1:1 and Latin text at
     * roughly four characters per token.
     *
     * @covers ::estimate_tokens
     */
    public function test_estimate_tokens(): void {
        $this->assertSame(0, openai_client::estimate_tokens(''));
        $this->assertSame(20, openai_client::estimate_tokens(str_repeat('あ', 20)));
        // 12 Latin chars -> ceil(12/4) = 3 tokens.
        $this->assertSame(3, openai_client::estimate_tokens('hello world!'));
    }
}
