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
 * Merges per-part alignment results back into one timeline.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Stitches segments from separately aligned mp3 parts into a single timeline.
 *
 * When a narration is too large for Whisper in one upload it is split by
 * {@see mp3_splitter} and each part aligned on its own. Whisper timestamps
 * every part from zero, so each part's segments must be shifted by the total
 * playing time of the parts before it.
 *
 * Offsets come from the frame-derived durations of the audio, not from the
 * previous part's last timestamp: Whisper's final segment can end before the
 * audio does (trailing silence yields no segment), and using it would pull
 * every later segment earlier and desynchronise the karaoke highlighting
 * progressively through the chapter.
 *
 * @package local_aireader
 */
class segment_stitcher {
    /**
     * Merge per-part segments into one ordered list with absolute timestamps.
     *
     * Parts with no segments still advance the offset, so silence or a part
     * Whisper found nothing in cannot shift the rest of the timeline.
     *
     * @param array $parts Ordered list of ['segments' => array, 'duration' => float],
     *                     where segments carry 'startms', 'endms' and 'segtext'
     *                     relative to the start of that part, and duration is the
     *                     part's playing time in seconds.
     * @return array Flat ordered segment list in the shape
     *               {@see segment_manager::store_for_asset()} consumes.
     */
    public static function stitch(array $parts): array {
        $out = [];
        $offsetms = 0;

        foreach ($parts as $part) {
            $segments = is_array($part['segments'] ?? null) ? $part['segments'] : [];
            foreach ($segments as $seg) {
                $text = trim((string)($seg['segtext'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $out[] = [
                    'startms' => $offsetms + max(0, (int)($seg['startms'] ?? 0)),
                    'endms'   => $offsetms + max(0, (int)($seg['endms'] ?? 0)),
                    'segtext' => $text,
                ];
            }
            $offsetms += (int)round(((float)($part['duration'] ?? 0)) * 1000);
        }

        return $out;
    }
}
