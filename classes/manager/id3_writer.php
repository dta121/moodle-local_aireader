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
 * Minimal, dependency-free ID3v2.3 tag writer for generated narration mp3s.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Writes a small ID3v2.3.0 metadata tag onto an mp3.
 *
 * OpenAI's TTS endpoint returns a bare mp3 with no metadata, so downloaded
 * narration files show up in music players as untitled tracks. This helper
 * prepends a standards-compliant ID3v2.3 tag (title, artist, album, genre,
 * track and a comment) so the file is recognisable offline and the
 * AI-generated disclosure travels with it.
 *
 * Text frames use the UTF-16 encoding (id 0x01) so non-Latin course and
 * activity names — including translated narrations — survive intact. The
 * implementation is deliberately self-contained rather than depending on
 * Moodle's bundled getID3, whose write support is not guaranteed across the
 * versions this plugin targets.
 *
 * @package local_aireader
 */
class id3_writer {
    /** Byte-order mark prefixed to every UTF-16LE string. */
    private const UTF16_BOM = "\xFF\xFE";

    /**
     * Prepend an ID3v2.3 tag built from $tags to an mp3, replacing any tag
     * already present at the very start of the stream.
     *
     * @param string $mp3 Raw mp3 bytes.
     * @param array $tags Tag map (see {@see build()} for recognised keys).
     * @return string The mp3 with a single leading ID3v2.3 tag.
     */
    public static function tag_mp3(string $mp3, array $tags): string {
        $tag = self::build($tags);
        if ($tag === '') {
            return $mp3;
        }
        return $tag . self::strip_leading_tag($mp3);
    }

    /**
     * Build a standalone ID3v2.3.0 tag from a map of human-readable values.
     *
     * Recognised keys: title, artist, album, genre, track, comment. Empty or
     * missing values are skipped. Returns an empty string when no usable value
     * is supplied.
     *
     * @param array $tags Tag map keyed by the names above.
     * @return string The complete ID3v2.3 tag, or '' when nothing to write.
     */
    public static function build(array $tags): string {
        $textframes = [
            'title'  => 'TIT2',
            'artist' => 'TPE1',
            'album'  => 'TALB',
            'genre'  => 'TCON',
            'track'  => 'TRCK',
        ];

        $frames = '';
        foreach ($textframes as $key => $frameid) {
            $value = isset($tags[$key]) ? trim((string)$tags[$key]) : '';
            if ($value !== '') {
                $frames .= self::frame($frameid, "\x01" . self::utf16($value));
            }
        }

        $comment = isset($tags['comment']) ? trim((string)$tags['comment']) : '';
        if ($comment !== '') {
            // COMM = encoding(1) + language(3) + short description + full text.
            // The short description is left empty (a bare UTF-16 terminator).
            $frames .= self::frame('COMM', "\x01" . 'eng' . "\x00\x00" . self::utf16($comment));
        }

        if ($frames === '') {
            return '';
        }

        // Header = "ID3" + version(2) + flags(1) + synchsafe size of the frames.
        return 'ID3' . "\x03\x00" . "\x00" . self::synchsafe(strlen($frames)) . $frames;
    }

    /**
     * Wrap a payload in an ID3v2.3 frame header.
     *
     * In ID3v2.3 the frame size is a plain 32-bit big-endian integer (unlike
     * the synchsafe size used by the tag header and by ID3v2.4 frames).
     *
     * @param string $id Four-character frame identifier.
     * @param string $payload Frame body.
     * @return string The encoded frame.
     */
    private static function frame(string $id, string $payload): string {
        return $id . pack('N', strlen($payload)) . "\x00\x00" . $payload;
    }

    /**
     * Encode a UTF-8 string as a BOM-prefixed, null-terminated UTF-16LE string.
     *
     * @param string $text UTF-8 input.
     * @return string UTF-16LE bytes suitable for an encoding-0x01 frame.
     */
    private static function utf16(string $text): string {
        return self::UTF16_BOM . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8') . "\x00\x00";
    }

    /**
     * Encode a length as a 28-bit synchsafe integer (4 bytes, 7 bits each).
     *
     * @param int $size Length in bytes.
     * @return string Four-byte synchsafe representation.
     */
    private static function synchsafe(int $size): string {
        return chr(($size >> 21) & 0x7F)
            . chr(($size >> 14) & 0x7F)
            . chr(($size >> 7) & 0x7F)
            . chr($size & 0x7F);
    }

    /**
     * Remove an ID3v2 tag from the start of an mp3 if one is present.
     *
     * Public because {@see mp3_splitter} needs to reach the first audio frame
     * of a stored narration, which carries the tag this class wrote.
     *
     * @param string $mp3 Raw mp3 bytes.
     * @return string The mp3 with any leading ID3v2 tag removed.
     */
    public static function strip_leading_tag(string $mp3): string {
        if (strlen($mp3) < 10 || strncmp($mp3, 'ID3', 3) !== 0) {
            return $mp3;
        }
        // Bytes 6-9 hold the synchsafe size of the tag body (header excluded).
        $size = (ord($mp3[6]) << 21) | (ord($mp3[7]) << 14) | (ord($mp3[8]) << 7) | ord($mp3[9]);
        $total = 10 + $size;
        // A set footer-present flag (bit 4 of the flags byte) adds a 10-byte footer.
        if ((ord($mp3[5]) & 0x10) !== 0) {
            $total += 10;
        }
        if ($total <= 0 || $total > strlen($mp3)) {
            return $mp3;
        }
        return substr($mp3, $total);
    }
}
