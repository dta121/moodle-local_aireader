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
}
