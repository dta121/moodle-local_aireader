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
 * External web service: return the aligned segments for a ready audio asset.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_aireader\manager\asset_manager;
use local_aireader\manager\segment_manager;

/**
 * Web service: load Whisper segments for a given asset.
 *
 * Called by the player when the user opens the transcript panel. We never
 * inline segments into get_status to keep that response small (long content
 * could push it over 1MB).
 *
 * @package local_aireader
 */
class get_transcript extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assetid' => new external_value(PARAM_INT, 'Asset id from get_status'),
        ]);
    }

    /**
     * Return structure: list of segments, ordered by idx.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'aligned'  => new external_value(PARAM_BOOL, 'Whether Whisper segments are available'),
            'segments' => new external_multiple_structure(
                new external_single_structure([
                    'idx'     => new external_value(PARAM_INT, '0-based position in the audio'),
                    'startms' => new external_value(PARAM_INT, 'Start time in milliseconds'),
                    'endms'   => new external_value(PARAM_INT, 'End time in milliseconds'),
                    'text'    => new external_value(PARAM_NOTAGS, 'Segment text'),
                ])
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $assetid
     * @return array
     */
    public static function execute(int $assetid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['assetid' => $assetid]);

        $asset = asset_manager::get_by_id((int)$params['assetid']);
        if (!$asset) {
            return ['aligned' => false, 'segments' => []];
        }

        $context = \context_module::instance((int)$asset->cmid);
        self::validate_context($context);
        require_capability('local/aireader:listen', $context);

        $rows = segment_manager::get_for_asset((int)$asset->id);
        $segments = [];
        foreach ($rows as $r) {
            $segments[] = [
                'idx'     => (int)$r->idx,
                'startms' => (int)$r->startms,
                'endms'   => (int)$r->endms,
                'text'    => (string)$r->segtext,
            ];
        }
        return [
            'aligned'  => !empty($segments),
            'segments' => $segments,
        ];
    }
}
