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
 * Tests for the cost estimator.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see cost_calculator}.
 *
 * @coversDefaultClass \local_aireader\manager\cost_calculator
 */
final class cost_calculator_test extends \advanced_testcase {
    /**
     * Known models price by their published per-million-character rate.
     *
     * @covers ::estimate_usd
     * @covers ::rate_for_model
     */
    public function test_estimate_usd_known_models(): void {
        // 1,000,000 chars -> exactly the per-million rate.
        $this->assertEqualsWithDelta(10.0, cost_calculator::estimate_usd('gpt-4o-mini-tts', 1000000), 0.0001);
        $this->assertEqualsWithDelta(15.0, cost_calculator::estimate_usd('tts-1', 1000000), 0.0001);
        $this->assertEqualsWithDelta(30.0, cost_calculator::estimate_usd('tts-1-hd', 1000000), 0.0001);
        // Scales linearly with character count.
        $this->assertEqualsWithDelta(0.5, cost_calculator::estimate_usd('gpt-4o-mini-tts', 50000), 0.0001);
    }

    /**
     * Model id matching is case-insensitive.
     *
     * @covers ::rate_for_model
     */
    public function test_rate_is_case_insensitive(): void {
        $this->assertSame(
            cost_calculator::rate_for_model('gpt-4o-mini-tts'),
            cost_calculator::rate_for_model('GPT-4o-Mini-TTS')
        );
    }

    /**
     * Unknown models fall back to the default rate, not null.
     *
     * @covers ::estimate_usd
     * @covers ::has_known_rate
     */
    public function test_unknown_model_uses_fallback(): void {
        $this->assertFalse(cost_calculator::has_known_rate('some-future-model'));
        $cost = cost_calculator::estimate_usd('some-future-model', 1000000);
        $this->assertNotNull($cost);
        $this->assertGreaterThan(0, $cost);
    }

    /**
     * A missing or zero character count yields a null (unknown) cost.
     *
     * @covers ::estimate_usd
     */
    public function test_missing_charcount_is_unknown(): void {
        $this->assertNull(cost_calculator::estimate_usd('gpt-4o-mini-tts', null));
        $this->assertNull(cost_calculator::estimate_usd('gpt-4o-mini-tts', 0));
    }

    /**
     * Formatting renders a dash for unknown and a 4dp dollar figure otherwise.
     *
     * @covers ::format_usd
     */
    public function test_format_usd(): void {
        $this->assertSame('—', cost_calculator::format_usd(null));
        $this->assertSame('$0.5000', cost_calculator::format_usd(0.5));
        $this->assertSame('$12.3457', cost_calculator::format_usd(12.34567));
    }
}
