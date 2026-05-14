<?php
namespace local_aireader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aireader\manager\asset_manager;
use local_aireader\manager\content_extractor;
use local_aireader\manager\storage;

defined('MOODLE_INTERNAL') || die();

/**
 * Web service: return audio status/URL for a Page or Book chapter.
 *
 * Side effect: this is also where the cache-first generation flow is anchored.
 * If a `ready` asset matching the current sourcehash exists, return it; if not,
 * (re)create the row, queue generation, and return `pending`. The browser polls.
 */
class get_status extends external_api {

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
            'status'        => new external_value(PARAM_ALPHA, 'pending|generating|ready|error|stale'),
            'audiourl'      => new external_value(PARAM_URL, 'mp3 URL or empty', VALUE_OPTIONAL),
            'durationsecs'  => new external_value(PARAM_INT, 'Duration in seconds', VALUE_OPTIONAL),
            'canregenerate' => new external_value(PARAM_BOOL, 'Whether the caller may force regen'),
            'message'       => new external_value(PARAM_RAW, 'Human-readable status'),
            'lastgenerated' => new external_value(PARAM_INT, 'UNIX timestamp', VALUE_OPTIONAL),
        ]);
    }

    public static function execute(int $cmid, string $module, int $chapterid = 0, string $lang = 'en'): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'module'    => $module,
            'chapterid' => $chapterid,
            'lang'      => $lang,
        ]);

        if (!in_array($params['module'], ['page', 'book'], true)) {
            throw new \invalid_parameter_exception('Unsupported module');
        }

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], $params['module']);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/aireader:listen', $context);

        $chapterid = $params['module'] === 'book' && $params['chapterid'] > 0
            ? (int)$params['chapterid']
            : null;
        if ($params['module'] === 'book' && $chapterid === null) {
            throw new \invalid_parameter_exception('chapterid required for mod_book');
        }

        $voice = (string)(get_config('local_aireader', 'voice') ?: 'marin');
        $model = (string)(get_config('local_aireader', 'model') ?: 'gpt-4o-mini-tts');

        $extracted = content_extractor::extract($params['module'], (int)$cm->id, $chapterid);
        $hash = asset_manager::compute_hash(
            $params['module'],
            (int)$cm->id,
            $chapterid,
            $params['lang'],
            $voice,
            $model,
            $extracted['text']
        );

        [$asset, $matched] = asset_manager::ensure_row([
            'courseid'    => (int)$course->id,
            'cmid'        => (int)$cm->id,
            'contextid'   => (int)$context->id,
            'module'      => $params['module'],
            'instanceid'  => (int)$cm->instance,
            'chapterid'   => $chapterid,
            'lang'        => $params['lang'],
            'voice'       => $voice,
            'model'       => $model,
            'sourcehash'  => $hash,
        ]);

        if (!$matched || $asset->status !== asset_manager::STATUS_READY) {
            if ($asset->status === asset_manager::STATUS_PENDING
                || $asset->status === asset_manager::STATUS_STALE
                || $asset->status === asset_manager::STATUS_ERROR) {
                asset_manager::queue_generation((int)$asset->id);
            }
        }

        $canregenerate = has_capability('local/aireader:manage', $context);

        if ($asset->status === asset_manager::STATUS_READY) {
            $url = storage::get_audio_url($asset);
            return [
                'status'        => 'ready',
                'audiourl'      => $url ? $url->out(false) : '',
                'durationsecs'  => (int)($asset->durationsecs ?? 0),
                'canregenerate' => $canregenerate,
                'message'       => get_string('status_ready', 'local_aireader'),
                'lastgenerated' => (int)($asset->lastgenerated ?? 0),
            ];
        }

        $messagekey = 'status_' . $asset->status;
        return [
            'status'        => $asset->status,
            'audiourl'      => '',
            'durationsecs'  => 0,
            'canregenerate' => $canregenerate,
            'message'       => get_string_manager()->string_exists($messagekey, 'local_aireader')
                ? get_string($messagekey, 'local_aireader')
                : get_string('status_pending', 'local_aireader'),
            'lastgenerated' => (int)($asset->lastgenerated ?? 0),
        ];
    }
}
