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
 * External web service: return audio status and URL.
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
use local_aireader\manager\content_extractor;
use local_aireader\manager\position_manager;
use local_aireader\manager\storage;

/**
 * Web service: return audio status/URL for a Page or Book chapter.
 *
 * Side effect: this is also where the cache-first generation flow is anchored.
 * If a `ready` asset matching the current sourcehash exists, return it; if not,
 * (re)create the row, queue generation, and return `pending`. The browser polls.
 *
 * @package local_aireader
 */
class get_status extends external_api {
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
            'status'         => new external_value(PARAM_ALPHA, 'pending|generating|ready|error|stale'),
            'audiourl'       => new external_value(PARAM_URL, 'mp3 URL or empty', VALUE_OPTIONAL),
            'downloadurl'    => new external_value(PARAM_URL, 'Force-download mp3 URL or empty', VALUE_OPTIONAL),
            'bytesize'       => new external_value(PARAM_INT, 'Audio file size in bytes', VALUE_OPTIONAL),
            'durationsecs'   => new external_value(PARAM_INT, 'Duration in seconds', VALUE_OPTIONAL),
            'canregenerate'  => new external_value(PARAM_BOOL, 'Whether the caller may force regen'),
            'message'        => new external_value(PARAM_TEXT, 'Human-readable status'),
            'lastgenerated'  => new external_value(PARAM_INT, 'UNIX timestamp', VALUE_OPTIONAL),
            'assetid'        => new external_value(PARAM_INT, 'Asset row id (for set_position)', VALUE_OPTIONAL),
            'resumeposition' => new external_value(PARAM_INT, 'Saved playback position in seconds', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Resolve current audio status for a Page or Book chapter; queue generation if missing.
     *
     * @param int $cmid Course module id.
     * @param string $module page|book
     * @param int $chapterid Book chapter id (0 for page).
     * @param string $lang Language code.
     * @return array Matches execute_returns().
     */
    public static function execute(int $cmid, string $module, int $chapterid = 0, string $lang = 'en'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'module'    => $module,
            'chapterid' => $chapterid,
            'lang'      => $lang,
        ]);

        if (!in_array($params['module'], ['page', 'book'], true)) {
            throw new \invalid_parameter_exception('Unsupported module');
        }

        // Server-side allowlist on the lang parameter. Without this any learner
        // with the listen capability could iterate ISO codes and force the plugin
        // to spend the OpenAI translation+TTS budget on hundreds of variants.
        if (!in_array($params['lang'], asset_manager::enabled_languages(), true)) {
            throw new \invalid_parameter_exception('Language not enabled on this site');
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
        if ($chapterid !== null) {
            asset_manager::assert_chapter_visible($cm, $chapterid, $context);
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
            $queueable = [
                asset_manager::STATUS_PENDING,
                asset_manager::STATUS_STALE,
                asset_manager::STATUS_ERROR,
            ];
            if (in_array($asset->status, $queueable, true)) {
                asset_manager::queue_generation((int)$asset->id);
            }
        }

        $canregenerate = has_capability('local/aireader:manage', $context);

        $estimatedduration = self::estimate_duration_seconds($extracted['text']);

        if ($asset->status === asset_manager::STATUS_READY) {
            $url = storage::get_audio_url($asset);
            $downloadurl = get_config('local_aireader', 'allow_downloads') === '0'
                ? null
                : storage::get_download_url($asset);
            return [
                'status'         => 'ready',
                'audiourl'       => $url ? $url->out(false) : '',
                'downloadurl'    => $downloadurl ? $downloadurl->out(false) : '',
                'bytesize'       => (int)($asset->bytesize ?? 0),
                'durationsecs'   => (int)($asset->durationsecs ?? 0) ?: $estimatedduration,
                'canregenerate'  => $canregenerate,
                'message'        => get_string('status_ready', 'local_aireader'),
                'lastgenerated'  => (int)($asset->lastgenerated ?? 0),
                'assetid'        => (int)$asset->id,
                'resumeposition' => position_manager::get((int)$USER->id, (int)$asset->id),
            ];
        }

        $messagekey = 'status_' . $asset->status;
        return [
            'status'         => $asset->status,
            'audiourl'       => '',
            'durationsecs'   => $estimatedduration,
            'canregenerate'  => $canregenerate,
            'message'        => get_string_manager()->string_exists($messagekey, 'local_aireader')
                ? get_string($messagekey, 'local_aireader')
                : get_string('status_pending', 'local_aireader'),
            'lastgenerated'  => (int)($asset->lastgenerated ?? 0),
            'assetid'        => (int)$asset->id,
            'resumeposition' => 0,
        ];
    }

    /**
     * Rough audio-duration estimate from cleaned source text.
     *
     * Used to fill the "~6 min listen" hint shown before audio is generated.
     * Treats the input as Latin-script prose at ~150 wpm; falls back to
     * mb_strlen/5 for CJK-style content where str_word_count under-counts.
     *
     * @param string $cleantext
     * @return int Estimated seconds.
     */
    private static function estimate_duration_seconds(string $cleantext): int {
        $words = str_word_count($cleantext);
        if ($words === 0) {
            $words = (int)max(1, round(mb_strlen($cleantext) / 5));
        }
        return (int)max(1, round($words * 60 / 150));
    }
}
