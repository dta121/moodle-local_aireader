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
 * Synthesises text, splitting and retrying when the endpoint says it is too long.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

use local_aireader\exception\tts_input_too_long;

/**
 * Turns the TTS input limit from a permanent failure into a retry.
 *
 * The plugin cannot predict how many tokens a chunk will cost. estimate_tokens()
 * assumes four characters per token, but translated text and dense punctuation
 * reach roughly 1.8, and no fixed ratio is safe for content not yet seen. So
 * rather than guessing better, this asks the endpoint and reacts to its answer:
 * on a too-long rejection the chunk is re-split and each piece retried.
 *
 * The synthesiser is injected as a callable so this logic is testable without
 * an HTTP seam, following the pattern generate_audio already uses when passing
 * a closure to {@see translation_manager::get_or_translate()}.
 *
 * @package local_aireader
 */
class tts_splitter {
    /**
     * @var int Maximum times a chunk may be subdivided before giving up.
     *
     * Four levels take a 2400-character chunk down to about 150 characters. At a
     * pathological four tokens per character that is ~600 tokens against a 2000
     * limit, so the depth, not any ratio estimate, is what makes this reliable.
     */
    public const MAX_DEPTH = 4;

    /** @var int Below this many characters a rejection is not an over-length problem. */
    public const MIN_PIECE_CHARS = 120;

    /**
     * Synthesise text, subdividing on an over-length rejection.
     *
     * @param callable $synth fn(string $piece): string returning audio bytes; expected to
     *                        throw {@see tts_input_too_long} when the piece is too long.
     * @param string $text The chunk to synthesise.
     * @param int $maxchars Character cap the chunk was built to.
     * @param int $depth Current recursion depth; callers pass 0.
     * @return string Concatenated audio bytes, in reading order.
     * @throws tts_input_too_long When the text cannot be subdivided any further.
     */
    public static function synthesize_split(
        callable $synth,
        string $text,
        int $maxchars,
        int $depth = 0
    ): string {
        try {
            return (string)$synth($text);
        } catch (tts_input_too_long $e) {
            // Refusing to subdivide further is deliberate: past this point the
            // rejection is not about length, and retrying only costs money.
            if ($depth >= self::MAX_DEPTH || mb_strlen($text) <= self::MIN_PIECE_CHARS) {
                throw $e;
            }
        }

        $target = max(self::MIN_PIECE_CHARS, (int)floor(($maxchars > 0 ? $maxchars : mb_strlen($text)) / 2));
        // Reuse the sentence-aware splitter so pieces break at sentence ends
        // where possible, which keeps the audio seams inaudible.
        $pieces = openai_client::chunk_text($text, $target);
        if (count($pieces) < 2) {
            // Nothing gained by recursing on an identical single piece; cut it
            // in half by characters instead so progress is guaranteed.
            $pieces = self::halve($text);
        }

        $audio = '';
        foreach ($pieces as $piece) {
            $audio .= self::synthesize_split($synth, $piece, $target, $depth + 1);
        }
        return $audio;
    }

    /**
     * Split text into two pieces, preferring the last space before the midpoint.
     *
     * @param string $text Text to halve.
     * @return array Either two pieces, or the original as a single piece when it cannot be cut.
     */
    private static function halve(string $text): array {
        $len = mb_strlen($text);
        if ($len < 2) {
            return [$text];
        }
        $mid = (int)floor($len / 2);
        $space = mb_strrpos(mb_substr($text, 0, $mid), ' ');
        $cut = ($space !== false && $space > 0) ? $space : $mid;
        $first = trim(mb_substr($text, 0, $cut));
        $second = trim(mb_substr($text, $cut));
        if ($first === '' || $second === '') {
            return [$text];
        }
        return [$first, $second];
    }
}
