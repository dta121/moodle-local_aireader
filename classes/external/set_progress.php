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
 * External web service: persist playback position and listened progress.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aireader\manager\asset_manager;
use local_aireader\manager\completion_manager;
use local_aireader\manager\position_manager;

/**
 * Web service: store resume position and distinct listened range.
 *
 * @package local_aireader
 */
class set_progress extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assetid'  => new external_value(PARAM_INT, 'Asset id from get_status'),
            'position' => new external_value(PARAM_INT, 'Resume position in seconds (>=0)'),
            'startms'  => new external_value(PARAM_INT, 'Start of the played range in milliseconds'),
            'endms'    => new external_value(PARAM_INT, 'Exclusive end of the played range in milliseconds'),
        ]);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'           => new external_value(PARAM_BOOL, 'Whether progress was stored'),
            'position'          => new external_value(PARAM_INT, 'Position now persisted'),
            'completionenabled' => new external_value(PARAM_BOOL, 'Whether AI Reader completion is active here'),
            'threshold'         => new external_value(PARAM_INT, 'Required listen percentage'),
            'listenedms'        => new external_value(PARAM_INT, 'Distinct listened milliseconds for this asset'),
            'percent'           => new external_value(PARAM_INT, 'Distinct listened percentage for this asset'),
            'completed'         => new external_value(PARAM_BOOL, 'Whether the source activity is complete'),
        ]);
    }

    /**
     * Persist position, merge the played range, and update completion if needed.
     *
     * @param int $assetid
     * @param int $position
     * @param int $startms
     * @param int $endms
     * @return array
     */
    public static function execute(int $assetid, int $position, int $startms, int $endms): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assetid'  => $assetid,
            'position' => $position,
            'startms'  => $startms,
            'endms'    => $endms,
        ]);

        $asset = asset_manager::get_by_id((int)$params['assetid']);
        if (!$asset) {
            return [
                'success' => false,
                'position' => 0,
                'completionenabled' => false,
                'threshold' => completion_manager::DEFAULT_THRESHOLD,
                'listenedms' => 0,
                'percent' => 0,
                'completed' => false,
            ];
        }

        $context = \context_module::instance((int)$asset->cmid);
        self::validate_context($context);
        require_capability('local/aireader:listen', $context);
        asset_manager::assert_asset_visible($asset, $context);

        $userid = (int)$USER->id;
        $position = max(0, (int)$params['position']);
        position_manager::set($userid, (int)$asset->id, $position);

        $completionconfig = completion_manager::get_config_for_cm((int)$asset->cmid);
        if (completion_manager::site_enabled() && $completionconfig && !empty($completionconfig->enabled)) {
            completion_manager::record_range($userid, $asset, (int)$params['startms'], (int)$params['endms']);
        }
        $completion = completion_manager::maybe_complete($userid, $asset);

        return [
            'success' => true,
            'position' => $position,
            'completionenabled' => $completion['enabled'],
            'threshold' => $completion['threshold'],
            'listenedms' => $completion['listenedms'],
            'percent' => $completion['percent'],
            'completed' => $completion['completed'],
        ];
    }
}
