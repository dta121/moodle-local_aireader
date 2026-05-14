<?php
namespace local_aireader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aireader\manager\asset_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Web service: mark any current asset stale and queue a fresh generation.
 */
class request_regen extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course module id'),
            'module'    => new external_value(PARAM_ALPHA, 'page|book'),
            'chapterid' => new external_value(PARAM_INT, 'Book chapter id (0 for none)', VALUE_DEFAULT, 0),
            'lang'      => new external_value(PARAM_LANG, 'Language', VALUE_DEFAULT, 'en'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_ALPHA, 'pending|generating|ready|error|stale'),
            'queued'  => new external_value(PARAM_BOOL, 'Whether a generation task was queued'),
            'message' => new external_value(PARAM_RAW, 'Human-readable status'),
        ]);
    }

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

        // No row yet: a get_status call will create one and queue generation.
        return [
            'status'  => asset_manager::STATUS_PENDING,
            'queued'  => false,
            'message' => get_string('status_pending', 'local_aireader'),
        ];
    }
}
