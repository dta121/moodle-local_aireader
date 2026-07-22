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
 * Tests for openai_translator request-payload construction.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see openai_translator}.
 *
 * @coversDefaultClass \local_aireader\manager\openai_translator
 */
final class openai_translator_test extends \advanced_testcase {
    /**
     * Non-reasoning models get the deterministic temperature and no
     * reasoning_effort.
     *
     * @covers ::build_payload
     */
    public function test_build_payload_for_sampling_models(): void {
        foreach (['gpt-4o-mini', 'gpt-4.1', 'gpt-5-chat-latest'] as $model) {
            $payload = openai_translator::build_payload($model, 'sys', 'text');
            $this->assertSame(0.2, $payload['temperature'], $model);
            $this->assertArrayNotHasKey('reasoning_effort', $payload, $model);
            $this->assertSame($model, $payload['model']);
            $this->assertSame('sys', $payload['messages'][0]['content']);
            $this->assertSame('text', $payload['messages'][1]['content']);
        }
    }

    /**
     * gpt-5-family reasoning models reject non-default temperature and take
     * minimal reasoning effort instead.
     *
     * @covers ::build_payload
     */
    public function test_build_payload_for_gpt5_reasoning_models(): void {
        foreach (['gpt-5-mini', 'gpt-5', 'gpt-5-nano', 'gpt-5.1'] as $model) {
            $payload = openai_translator::build_payload($model, 'sys', 'text');
            $this->assertArrayNotHasKey('temperature', $payload, $model);
            $this->assertSame('minimal', $payload['reasoning_effort'], $model);
        }
    }

    /**
     * o-series reasoning models get neither temperature (rejected) nor
     * 'minimal' reasoning effort (gpt-5-only value).
     *
     * @covers ::build_payload
     */
    public function test_build_payload_for_o_series_models(): void {
        foreach (['o3-mini', 'o4-mini'] as $model) {
            $payload = openai_translator::build_payload($model, 'sys', 'text');
            $this->assertArrayNotHasKey('temperature', $payload, $model);
            $this->assertArrayNotHasKey('reasoning_effort', $payload, $model);
        }
    }
}
