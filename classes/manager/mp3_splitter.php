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
 * Minimal, dependency-free mp3 frame splitter for oversized narrations.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Splits an mp3 into parts small enough to upload, on frame boundaries.
 *
 * Whisper rejects uploads over 25 MiB, so long narrations could never be
 * aligned and their tasks failed on every retry forever. An mp3 is a plain
 * sequence of self-contained frames, so cutting between frames yields parts
 * that are each a valid mp3 stream. That lets alignment run per part and the
 * timestamps be stitched back together (see {@see segment_stitcher}).
 *
 * Deliberately self-contained rather than shelling out to ffmpeg, which Moodle
 * does not ship and which cannot be assumed present on a managed host, and
 * rather than depending on getID3, whose behaviour is not guaranteed across the
 * Moodle versions this plugin targets.
 *
 * @package local_aireader
 */
class mp3_splitter {
    /** Bitrates (kbps) for MPEG-1 Layer III, indexed by the header's bitrate index. */
    private const BITRATES_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];

    /** Bitrates (kbps) for MPEG-2 and MPEG-2.5 Layer III. */
    private const BITRATES_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];

    /** Sample rates (Hz) keyed by MPEG version bits, then by sample-rate index. */
    private const SAMPLERATES = [
        3 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        0 => [11025, 12000, 8000],
    ];

    /**
     * Split raw mp3 bytes into parts of at most $maxbytes, cutting only between frames.
     *
     * Each part always contains at least one frame, so a part can exceed
     * $maxbytes only in the impossible case of a single frame larger than it
     * (the format caps a frame at roughly 1.5 KB). Parts carry no ID3 tag,
     * which is valid: a bare frame sequence is a playable mp3 stream.
     *
     * @param string $mp3 Raw mp3 bytes, with or without ID3 tags.
     * @param int $maxbytes Maximum bytes per part; values below 1 are treated as 1.
     * @return array List of ['bytes' => string, 'duration' => float] in stream order,
     *               empty when no valid frame could be found.
     */
    public static function split(string $mp3, int $maxbytes): array {
        $maxbytes = max(1, $maxbytes);
        $audio = id3_writer::strip_leading_tag($mp3);
        $audio = self::strip_trailing_tag($audio);
        $len = strlen($audio);

        $parts = [];
        // Offsets rather than string concatenation: a 36 MB narration is ~26k
        // frames, and appending per frame would be quadratic.
        $startoffset = 0;
        $partbytes = 0;
        $partduration = 0.0;
        $offset = 0;

        while ($offset + 4 <= $len) {
            $frame = self::read_frame_header($audio, $offset);
            if ($frame === null) {
                // Not a frame here. Resync one byte at a time over the garbage.
                $offset++;
                continue;
            }
            [$framelen, $frameduration] = $frame;
            if ($offset + $framelen > $len) {
                // Truncated final frame: drop it rather than upload a partial frame.
                break;
            }
            if ($partbytes > 0 && $partbytes + $framelen > $maxbytes) {
                $parts[] = [
                    'bytes' => substr($audio, $startoffset, $partbytes),
                    'duration' => $partduration,
                ];
                $startoffset = $offset;
                $partbytes = 0;
                $partduration = 0.0;
            }
            if ($partbytes === 0) {
                $startoffset = $offset;
            }
            $partbytes += $framelen;
            $partduration += $frameduration;
            $offset += $framelen;
        }

        if ($partbytes > 0) {
            $parts[] = [
                'bytes' => substr($audio, $startoffset, $partbytes),
                'duration' => $partduration,
            ];
        }

        return $parts;
    }

    /**
     * Total playing time of an mp3, in seconds, summed from its frame headers.
     *
     * @param string $mp3 Raw mp3 bytes.
     * @return float Duration in seconds; 0.0 when no valid frame is found.
     */
    public static function duration(string $mp3): float {
        $total = 0.0;
        foreach (self::split($mp3, PHP_INT_MAX) as $part) {
            $total += (float)$part['duration'];
        }
        return $total;
    }

    /**
     * Decode a Layer III frame header.
     *
     * Bitrate and sample rate are read per frame rather than once, because
     * variable bitrate streams are real and a single read would desynchronise
     * the walk.
     *
     * @param string $audio Frame data.
     * @param int $offset Byte offset of the candidate header.
     * @return array|null [frame length in bytes, frame duration in seconds], or null when
     *                    $offset does not begin a valid MPEG Layer III frame.
     */
    private static function read_frame_header(string $audio, int $offset): ?array {
        $b0 = ord($audio[$offset]);
        $b1 = ord($audio[$offset + 1]);
        // Frame sync: eleven set bits.
        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
            return null;
        }
        $version = ($b1 >> 3) & 0x03;
        $layer = ($b1 >> 1) & 0x03;
        // Version bits 1 are reserved; layer bits 1 mean Layer III, which is all mp3 uses here.
        if ($version === 1 || $layer !== 1) {
            return null;
        }

        $b2 = ord($audio[$offset + 2]);
        $bitrateindex = ($b2 >> 4) & 0x0F;
        $samplerateindex = ($b2 >> 2) & 0x03;
        $padding = ($b2 >> 1) & 0x01;
        // Index 0 is "free format" and 15 is invalid; neither is a size we can compute.
        if ($bitrateindex === 0 || $bitrateindex === 15 || $samplerateindex === 3) {
            return null;
        }

        $ismpeg1 = ($version === 3);
        $bitrate = $ismpeg1 ? self::BITRATES_V1[$bitrateindex] : self::BITRATES_V2[$bitrateindex];
        $samplerate = self::SAMPLERATES[$version][$samplerateindex];
        if ($bitrate === 0 || $samplerate === 0) {
            return null;
        }

        // MPEG-1 Layer III carries 1152 samples per frame, MPEG-2/2.5 carry 576.
        $samplesperframe = $ismpeg1 ? 1152 : 576;
        $framelen = (int)(($samplesperframe / 8) * 1000 * $bitrate / $samplerate) + $padding;
        if ($framelen <= 4) {
            return null;
        }

        return [$framelen, $samplesperframe / $samplerate];
    }

    /**
     * Remove a 128-byte ID3v1 tag from the end of an mp3 if one is present.
     *
     * @param string $audio Raw mp3 bytes.
     * @return string The mp3 with any trailing ID3v1 tag removed.
     */
    private static function strip_trailing_tag(string $audio): string {
        if (strlen($audio) >= 128 && strncmp(substr($audio, -128, 3), 'TAG', 3) === 0) {
            return substr($audio, 0, -128);
        }
        return $audio;
    }
}
