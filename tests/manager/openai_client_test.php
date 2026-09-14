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
        // Roughly 4800 wide chars: under the 3800 char cap only after splitting,
        // and far over a single chunk's token budget.
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

    /**
     * Token-capped models get the tighter character ceiling; character-capped ones do not.
     *
     * @covers ::chunk_size_for
     */
    public function test_chunk_size_for_is_model_aware(): void {
        $this->assertSame(2400, openai_client::chunk_size_for('gpt-4o-mini-tts', 3800));
        $this->assertSame(3800, openai_client::chunk_size_for('tts-1', 3800));
        $this->assertSame(3800, openai_client::chunk_size_for('tts-1-hd', 5000));
    }

    /**
     * A smaller configured chunk size is honoured; a larger one is clamped.
     *
     * The clamp is what actually fixes the live site: its stored chunk_size is
     * the historic 3800, so changing only the default would have changed nothing.
     *
     * @covers ::chunk_size_for
     */
    public function test_chunk_size_for_clamps_rather_than_overrides(): void {
        $this->assertSame(900, openai_client::chunk_size_for('gpt-4o-mini-tts', 900));
        $this->assertSame(2400, openai_client::chunk_size_for('gpt-4o-mini-tts', 99999));
        $this->assertSame(2400, openai_client::chunk_size_for('gpt-4o-mini-tts', 0));
        $this->assertSame(3800, openai_client::chunk_size_for('tts-1', -5));
    }

    /**
     * The estimator alone cannot enforce the token limit, which is why the
     * character cap and the split-and-retry carry the guarantee.
     *
     * Documents the defect rather than asserting a fix: reaching the 1800-token
     * ceiling needs 7200+ characters, which no chunk size here permits.
     *
     * @covers ::estimate_tokens
     */
    public function test_token_ceiling_is_unreachable_within_the_character_cap(): void {
        $maxchunk = str_repeat('a', openai_client::MAX_CHUNK_SIZE_TOKEN_CAPPED);

        $estimated = openai_client::estimate_tokens($maxchunk);

        $this->assertLessThan(openai_client::DEFAULT_MAX_TOKENS, $estimated);
    }
}
