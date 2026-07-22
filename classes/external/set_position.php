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
 * External web service: persist the current learner's playback position.
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
use local_aireader\manager\position_manager;

/**
 * Web service: store the learner's current playback position in an asset.
 *
 * Called every ~5 seconds from the player, plus on pause/ended/visibility-hide.
 * The caller is the listener themselves; their `$USER->id` is used as the
 * key — no impersonation across users is possible.
 *
 * @package local_aireader
 */
class set_position extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assetid'  => new external_value(PARAM_INT, 'Asset id from get_status'),
            'position' => new external_value(PARAM_INT, 'Position in seconds (>=0)'),
        ]);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, 'Whether the position was stored'),
            'position' => new external_value(PARAM_INT, 'Position now persisted'),
        ]);
    }

    /**
     * Persist the position.
     *
     * @param int $assetid
     * @param int $position
     * @return array
     */
    public static function execute(int $assetid, int $position): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assetid'  => $assetid,
            'position' => $position,
        ]);

        $asset = asset_manager::get_by_id((int)$params['assetid']);
        if (!$asset) {
            // Asset has been purged or never existed; silently no-op so the
            // client doesn't blow up on stale assetids it cached locally.
            return ['success' => false, 'position' => 0];
        }

        $context = \context_module::instance((int)$asset->cmid);
        self::validate_context($context);
        require_capability('local/aireader:listen', $context);
        asset_manager::assert_asset_visible($asset, $context);
        asset_manager::assert_narration_available(
            (string)$asset->module,
            (int)$asset->cmid,
            (int)$asset->chapterid,
            $context
        );

        $position = max(0, (int)$params['position']);
        position_manager::set((int)$USER->id, (int)$asset->id, $position);

        return ['success' => true, 'position' => $position];
    }
}
