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
 * Unit tests for merging per-part alignment results.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see segment_stitcher}.
 *
 * @coversDefaultClass \local_aireader\manager\segment_stitcher
 */
final class segment_stitcher_test extends \advanced_testcase {
    /**
     * Later parts are shifted by the playing time of everything before them.
     *
     * @covers ::stitch
     */
    public function test_later_parts_are_offset_by_preceding_duration(): void {
        $stitched = segment_stitcher::stitch([
            ['duration' => 10.0, 'segments' => [
                ['startms' => 0, 'endms' => 4000, 'segtext' => 'one'],
                ['startms' => 4000, 'endms' => 9000, 'segtext' => 'two'],
            ]],
            ['duration' => 8.0, 'segments' => [
                ['startms' => 0, 'endms' => 3000, 'segtext' => 'three'],
            ]],
        ]);

        $this->assertSame(['one', 'two', 'three'], array_column($stitched, 'segtext'));
        $this->assertSame(0, $stitched[0]['startms']);
        $this->assertSame(9000, $stitched[1]['endms']);
        $this->assertSame(10000, $stitched[2]['startms']);
        $this->assertSame(13000, $stitched[2]['endms']);
    }

    /**
     * A part Whisper returned nothing for still advances the clock.
     *
     * Offsets come from audio duration, not from the previous segment's end. If
     * they came from the segments, a silent part would pull the whole remainder
     * of the chapter earlier and the highlighting would drift.
     *
     * @covers ::stitch
     */
    public function test_a_part_without_segments_still_advances_the_offset(): void {
        $stitched = segment_stitcher::stitch([
            ['duration' => 5.0, 'segments' => []],
            ['duration' => 5.0, 'segments' => [['startms' => 0, 'endms' => 1000, 'segtext' => 'late']]],
        ]);

        $this->assertCount(1, $stitched);
        $this->assertSame(5000, $stitched[0]['startms']);
        $this->assertSame(6000, $stitched[0]['endms']);
    }

    /**
     * Trailing silence in a part cannot shift what follows it.
     *
     * @covers ::stitch
     */
    public function test_offsets_ignore_where_the_last_segment_ended(): void {
        $stitched = segment_stitcher::stitch([
            // 30 seconds of audio whose speech stops at 12 seconds.
            ['duration' => 30.0, 'segments' => [['startms' => 0, 'endms' => 12000, 'segtext' => 'early']]],
            ['duration' => 10.0, 'segments' => [['startms' => 0, 'endms' => 2000, 'segtext' => 'next']]],
        ]);

        $this->assertSame(30000, $stitched[1]['startms']);
    }

    /**
     * Empty input and blank segment text produce nothing.
     *
     * @covers ::stitch
     */
    public function test_empty_and_blank_input(): void {
        $this->assertSame([], segment_stitcher::stitch([]));
        $this->assertSame([], segment_stitcher::stitch([
            ['duration' => 1.0, 'segments' => [['startms' => 0, 'endms' => 1, 'segtext' => '   ']]],
        ]));
        $this->assertSame([], segment_stitcher::stitch([['duration' => 1.0]]));
    }

    /**
     * Fractional durations round to whole milliseconds without drifting.
     *
     * @covers ::stitch
     */
    public function test_fractional_durations_round_cleanly(): void {
        $stitched = segment_stitcher::stitch([
            ['duration' => 2.6122, 'segments' => [['startms' => 0, 'endms' => 100, 'segtext' => 'a']]],
            ['duration' => 2.6122, 'segments' => [['startms' => 0, 'endms' => 100, 'segtext' => 'b']]],
        ]);

        $this->assertSame(2612, $stitched[1]['startms']);
    }
}
