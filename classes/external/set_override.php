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
 * External web service: enable or disable narration for a page or book chapter.
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
use local_aireader\manager\override_manager;

/**
 * Web service: persist an enable/disable override for a page or book chapter.
 *
 * @package local_aireader
 */
class set_override extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course module id'),
            'module'    => new external_value(PARAM_ALPHA, 'page|book'),
            'chapterid' => new external_value(PARAM_INT, 'Book chapter id (0 = activity-level default)', VALUE_DEFAULT, 0),
            'enabled'   => new external_value(PARAM_BOOL, 'Whether narration should be enabled at this scope'),
        ]);
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'New effective state at the requested scope'),
            'scope'   => new external_value(PARAM_ALPHA, 'activity|chapter'),
        ]);
    }

    /**
     * Save the override.
     *
     * @param int $cmid Course module id.
     * @param string $module page|book
     * @param int $chapterid Book chapter id (0 for activity default).
     * @param bool $enabled Desired state.
     * @return array Matches execute_returns().
     */
    public static function execute(int $cmid, string $module, int $chapterid, bool $enabled): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'module'    => $module,
            'chapterid' => $chapterid,
            'enabled'   => $enabled,
        ]);

        if (!in_array($params['module'], ['page', 'book'], true)) {
            throw new \invalid_parameter_exception('Unsupported module');
        }
        if ($params['module'] === 'page' && $params['chapterid'] !== 0) {
            throw new \invalid_parameter_exception('chapterid must be 0 for mod_page');
        }

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], $params['module']);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/aireader:manage', $context);

        if ($params['module'] === 'book' && $params['chapterid'] > 0) {
            asset_manager::assert_chapter_visible($cm, (int)$params['chapterid'], $context);
        }

        override_manager::set(
            (int)$course->id,
            (int)$cm->id,
            (int)$params['chapterid'],
            (bool)$params['enabled']
        );

        return [
            'enabled' => (bool)$params['enabled'],
            'scope'   => $params['chapterid'] > 0 ? 'chapter' : 'activity',
        ];
    }
}
