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
 * External web service: queue regeneration of audio for a Page or Book chapter.
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

/**
 * Web service: mark any current asset stale and queue a fresh generation.
 *
 * @package local_aireader
 */
class request_regen extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course module id'),
            'module'    => new external_value(PARAM_ALPHA, 'page|book'),
            'chapterid' => new external_value(PARAM_INT, 'Book chapter id (0 for none)', VALUE_DEFAULT, 0),
            'lang'      => new external_value(PARAM_ALPHANUMEXT, 'Language code (e.g. en, es, zh_cn)', VALUE_DEFAULT, 'en'),
        ]);
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_ALPHA, 'pending|generating|ready|error|stale'),
            'queued'  => new external_value(PARAM_BOOL, 'Whether a generation task was queued'),
            'message' => new external_value(PARAM_RAW, 'Human-readable status'),
        ]);
    }

    /**
     * Queue regeneration of audio for a Page or Book chapter variant.
     *
     * @param int $cmid Course module id.
     * @param string $module page|book
     * @param int $chapterid Book chapter id (0 for page).
     * @param string $lang Language code.
     * @return array Matches execute_returns().
     */
    public static function execute(int $cmid, string $module, int $chapterid = 0, string $lang = 'en'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'module'    => $module,
            'chapterid' => $chapterid,
            'lang'      => $lang,
        ]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], $params['module']);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/aireader:manage', $context);

        $chapterid = $params['module'] === 'book' && $params['chapterid'] > 0
            ? (int)$params['chapterid']
            : null;

        $voice = (string)(get_config('local_aireader', 'voice') ?: 'marin');
        $model = (string)(get_config('local_aireader', 'model') ?: 'gpt-4o-mini-tts');

        $existing = asset_manager::find_current(
            (int)$cm->id,
            $chapterid,
            $params['lang'],
            $voice,
            $model
        );

        if ($existing) {
            asset_manager::update_status((int)$existing->id, asset_manager::STATUS_PENDING);
            asset_manager::queue_generation((int)$existing->id);
            return [
                'status'  => asset_manager::STATUS_PENDING,
                'queued'  => true,
                'message' => get_string('status_pending', 'local_aireader'),
            ];
        }

        return [
            'status'  => asset_manager::STATUS_PENDING,
            'queued'  => false,
            'message' => get_string('status_pending', 'local_aireader'),
        ];
    }
}
