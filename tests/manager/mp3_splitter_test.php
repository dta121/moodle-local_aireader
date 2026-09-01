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
 * Unit tests for the mp3 frame splitter.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see mp3_splitter}.
 *
 * Fixtures are synthesised from real MPEG-1 Layer III frame headers rather than
 * committed as a binary, so the expected frame lengths and durations are
 * derivable in the test itself.
 *
 * @coversDefaultClass \local_aireader\manager\mp3_splitter
 */
final class mp3_splitter_test extends \advanced_testcase {
    /** Sample rate used by every fixture frame. */
    private const RATE = 44100;

    /** Samples per frame for MPEG-1 Layer III. */
    private const SAMPLES = 1152;

    /**
     * Build one MPEG-1 Layer III mono frame at the given bitrate.
     *
     * @param int $kbps Bitrate in kbps; must appear in the Layer III table.
     * @return string Raw frame bytes, zero-padded after the 4-byte header.
     */
    private function frame(int $kbps): string {
        $table = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        $index = (int)array_search($kbps, $table, true);
        // FF FB = sync + MPEG-1 + Layer III + no CRC; last byte selects mono.
        $header = "\xFF\xFB" . chr($index << 4) . "\xC0";
        $length = (int)((self::SAMPLES / 8) * 1000 * $kbps / self::RATE);
        return $header . str_repeat("\x00", $length - 4);
    }

    /**
     * Build an ID3v2.3 tag with an empty body of the given size.
     *
     * @param int $bodysize Bytes of tag body.
     * @return string The complete tag.
     */
    private function id3v2(int $bodysize): string {
        $synchsafe = chr(($bodysize >> 21) & 0x7F) . chr(($bodysize >> 14) & 0x7F)
            . chr(($bodysize >> 7) & 0x7F) . chr($bodysize & 0x7F);
        return 'ID3' . "\x03\x00" . "\x00" . $synchsafe . str_repeat("\x00", $bodysize);
    }

    /**
     * A 128 kbps 44.1 kHz frame is 417 bytes, which anchors every other expectation.
     *
     * @covers ::split
     */
    public function test_frame_length_matches_the_format(): void {
        $this->assertSame(417, strlen($this->frame(128)));
    }

    /**
     * Splitting then concatenating returns the original frame stream exactly.
     *
     * @covers ::split
     */
    public function test_parts_reassemble_to_the_original_stream(): void {
        $audio = str_repeat($this->frame(128), 100);

        $parts = mp3_splitter::split($audio, 10000);

        $this->assertGreaterThan(1, count($parts));
        $this->assertSame($audio, implode('', array_column($parts, 'bytes')));
    }

    /**
     * No part may exceed the requested ceiling, and each must start on a frame sync.
     *
     * @covers ::split
     */
    public function test_parts_respect_the_ceiling_and_start_on_a_frame(): void {
        $parts = mp3_splitter::split(str_repeat($this->frame(128), 100), 10000);

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(10000, strlen($part['bytes']));
            $this->assertSame("\xFF", substr($part['bytes'], 0, 1));
        }
    }

    /**
     * ID3v2 and ID3v1 tags are excluded, so only audio frames are uploaded.
     *
     * @covers ::split
     */
    public function test_tags_are_stripped(): void {
        $audio = str_repeat($this->frame(128), 20);
        $tagged = $this->id3v2(64) . $audio . 'TAG' . str_repeat("\x00", 125);

        $joined = implode('', array_column(mp3_splitter::split($tagged, 10000), 'bytes'));

        $this->assertSame($audio, $joined);
        $this->assertStringStartsNotWith('ID3', $joined);
    }

    /**
     * Durations sum to the true playing time, which is what timestamp offsets rely on.
     *
     * @covers ::split
     * @covers ::duration
     */
    public function test_durations_sum_to_the_whole_stream(): void {
        $audio = str_repeat($this->frame(128), 100);
        $expected = 100 * self::SAMPLES / self::RATE;

        $parts = mp3_splitter::split($audio, 10000);

        $this->assertEqualsWithDelta($expected, array_sum(array_column($parts, 'duration')), 0.0001);
        $this->assertEqualsWithDelta($expected, mp3_splitter::duration($audio), 0.0001);
    }

    /**
     * Bitrate is read per frame, so a variable bitrate stream survives intact.
     *
     * @covers ::split
     */
    public function test_variable_bitrate_is_handled_per_frame(): void {
        $audio = '';
        for ($i = 0; $i < 60; $i++) {
            $audio .= $this->frame($i % 2 === 0 ? 128 : 192);
        }

        $parts = mp3_splitter::split($audio, 5000);

        $this->assertSame($audio, implode('', array_column($parts, 'bytes')));
        $this->assertEqualsWithDelta(
            60 * self::SAMPLES / self::RATE,
            array_sum(array_column($parts, 'duration')),
            0.0001
        );
    }

    /**
     * Unparseable input yields no parts, which the caller reads as "cannot split".
     *
     * @covers ::split
     */
    public function test_unparseable_input_yields_no_parts(): void {
        $this->assertSame([], mp3_splitter::split(str_repeat("\x12\x34", 500), 1000));
        $this->assertSame([], mp3_splitter::split('', 1000));
    }

    /**
     * Leading bytes that are not a frame are skipped rather than corrupting the walk.
     *
     * @covers ::split
     */
    public function test_leading_garbage_is_resynced_past(): void {
        $audio = str_repeat($this->frame(128), 20);

        $parts = mp3_splitter::split(str_repeat("\x00", 37) . $audio, 10000);

        $this->assertSame($audio, implode('', array_column($parts, 'bytes')));
    }

    /**
     * A truncated final frame is dropped rather than uploaded as a partial frame.
     *
     * @covers ::split
     */
    public function test_truncated_final_frame_is_dropped(): void {
        $audio = str_repeat($this->frame(128), 20);
        $truncated = $audio . substr($this->frame(128), 0, 200);

        $parts = mp3_splitter::split($truncated, 10000);

        $this->assertSame($audio, implode('', array_column($parts, 'bytes')));
    }

    /**
     * The real case: a narration over Whisper's limit splits into uploadable parts.
     *
     * @covers ::split
     */
    public function test_oversized_narration_splits_under_the_upload_target(): void {
        // ~35.9 MB, matching the asset that failed in production.
        $audio = str_repeat($this->frame(128), 86000);
        $target = \local_aireader\task\align_audio::PART_TARGET_BYTES;

        $parts = mp3_splitter::split($audio, $target);

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual($target, strlen($part['bytes']));
            $this->assertLessThan(\local_aireader\task\align_audio::MAX_UPLOAD_BYTES, strlen($part['bytes']));
        }
        $this->assertSame($audio, implode('', array_column($parts, 'bytes')));
    }
}
