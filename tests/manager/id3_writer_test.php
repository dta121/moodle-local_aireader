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
 * Unit tests for the ID3v2.3 tag writer.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see id3_writer}.
 *
 * @coversDefaultClass \local_aireader\manager\id3_writer
 */
final class id3_writer_test extends \advanced_testcase {
    /**
     * A tag opens with the ID3v2.3.0 signature and a correct synchsafe size.
     *
     * @covers ::build
     */
    public function test_build_header_is_well_formed(): void {
        $tag = id3_writer::build(['title' => 'Hello']);

        $this->assertStringStartsWith("ID3\x03\x00\x00", $tag);
        $declared = (ord($tag[6]) << 21) | (ord($tag[7]) << 14) | (ord($tag[8]) << 7) | ord($tag[9]);
        $this->assertSame(strlen($tag) - 10, $declared);
    }

    /**
     * Every supplied value lands in its corresponding frame.
     *
     * @covers ::build
     */
    public function test_build_emits_expected_frames(): void {
        $tag = id3_writer::build([
            'title'   => 'Intro',
            'artist'  => 'Saylor Academy',
            'album'   => 'Statistics 101',
            'genre'   => 'Speech',
            'track'   => '3',
            'comment' => 'AI-generated narration',
        ]);

        $frames = self::parse_frames($tag);
        $this->assertSame('Intro', self::decode_text($frames['TIT2']));
        $this->assertSame('Saylor Academy', self::decode_text($frames['TPE1']));
        $this->assertSame('Statistics 101', self::decode_text($frames['TALB']));
        $this->assertSame('Speech', self::decode_text($frames['TCON']));
        $this->assertSame('3', self::decode_text($frames['TRCK']));
        $this->assertSame('AI-generated narration', self::decode_comment($frames['COMM']));
    }

    /**
     * Non-Latin text survives the UTF-16 round trip.
     *
     * @covers ::build
     */
    public function test_unicode_titles_round_trip(): void {
        $title = 'Introducción a la estadística — 統計学';
        $frames = self::parse_frames(id3_writer::build(['title' => $title]));
        $this->assertSame($title, self::decode_text($frames['TIT2']));
    }

    /**
     * Empty or missing values produce no frame; an all-empty map yields no tag.
     *
     * @covers ::build
     */
    public function test_empty_values_are_skipped(): void {
        $frames = self::parse_frames(id3_writer::build(['title' => 'Only title', 'track' => '', 'album' => '   ']));
        $this->assertArrayHasKey('TIT2', $frames);
        $this->assertArrayNotHasKey('TRCK', $frames);
        $this->assertArrayNotHasKey('TALB', $frames);

        $this->assertSame('', id3_writer::build([]));
        $this->assertSame('', id3_writer::build(['title' => '', 'comment' => '   ']));
    }

    /**
     * Tagging an untagged stream prepends the tag and preserves the audio.
     *
     * @covers ::tag_mp3
     */
    public function test_tag_mp3_prepends_and_preserves_audio(): void {
        $audio = "\xFF\xFB\x90\x00" . str_repeat("\x12\x34", 64);
        $out = id3_writer::tag_mp3($audio, ['title' => 'X']);

        $this->assertStringStartsWith('ID3', $out);
        $this->assertSame($audio, substr($out, -strlen($audio)));
    }

    /**
     * Re-tagging replaces the existing leading tag rather than stacking a
     * second one, leaving the original audio bytes untouched.
     *
     * @covers ::tag_mp3
     * @covers ::build
     */
    public function test_tag_mp3_replaces_existing_tag(): void {
        $audio = "\xFF\xFB\x90\x00" . str_repeat("\x12\x34", 64);
        $first = id3_writer::tag_mp3($audio, ['title' => 'First']);
        $second = id3_writer::tag_mp3($first, ['title' => 'Second']);

        // Exactly one tag: the bytes after the parsed tag length equal the audio.
        $size = (ord($second[6]) << 21) | (ord($second[7]) << 14) | (ord($second[8]) << 7) | ord($second[9]);
        $this->assertSame($audio, substr($second, 10 + $size));

        $frames = self::parse_frames($second);
        $this->assertSame('Second', self::decode_text($frames['TIT2']));
    }

    /**
     * Parse an ID3v2.3 tag into a frame-id => payload map.
     *
     * @param string $tag ID3v2.3 tag bytes.
     * @return array<string, string>
     */
    private static function parse_frames(string $tag): array {
        $size = (ord($tag[6]) << 21) | (ord($tag[7]) << 14) | (ord($tag[8]) << 7) | ord($tag[9]);
        $frames = [];
        $pos = 10;
        $end = 10 + $size;
        while ($pos + 10 <= $end) {
            $id = substr($tag, $pos, 4);
            if (!preg_match('/^[A-Z0-9]{4}$/', $id)) {
                break;
            }
            $fsize = unpack('N', substr($tag, $pos + 4, 4))[1];
            $frames[$id] = substr($tag, $pos + 10, $fsize);
            $pos += 10 + $fsize;
        }
        return $frames;
    }

    /**
     * Decode a UTF-16 text-frame payload back to UTF-8.
     *
     * @param string $payload Frame body (leading encoding byte included).
     * @return string
     */
    private static function decode_text(string $payload): string {
        $data = substr($payload, 1);
        if (substr($data, 0, 2) === "\xFF\xFE") {
            $data = substr($data, 2);
        }
        return rtrim(mb_convert_encoding($data, 'UTF-8', 'UTF-16LE'), "\x00");
    }

    /**
     * Decode the text of a COMM frame (encoding + 3-byte language + empty
     * description + UTF-16 text) back to UTF-8.
     *
     * @param string $payload COMM frame body.
     * @return string
     */
    private static function decode_comment(string $payload): string {
        $data = substr($payload, 4);
        if (substr($data, 0, 2) === "\x00\x00") {
            $data = substr($data, 2);
        }
        if (substr($data, 0, 2) === "\xFF\xFE") {
            $data = substr($data, 2);
        }
        return rtrim(mb_convert_encoding($data, 'UTF-8', 'UTF-16LE'), "\x00");
    }
}
