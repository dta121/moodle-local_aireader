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
 * Hook callbacks that inject the player on supported resource views.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader;

use local_aireader\manager\asset_manager;
use local_aireader\manager\openai_translator;
use local_aireader\manager\override_manager;

/**
 * Hook callbacks that inject the player on supported resource views.
 *
 * @package local_aireader
 */
class hook_callbacks {
    /**
     * Inject the player mount point and bootstrap JS on mod_page and mod_book views.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function inject_player(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE;

        if (!get_config('local_aireader', 'enabled')) {
            return;
        }

        if ($PAGE->pagelayout !== 'incourse' && $PAGE->pagelayout !== 'standard') {
            return;
        }
        if (!$PAGE->cm) {
            return;
        }

        $modname = $PAGE->cm->modname;
        if ($modname !== 'page' && $modname !== 'book') {
            return;
        }
        if ($modname === 'page' && !get_config('local_aireader', 'enable_page')) {
            return;
        }
        if ($modname === 'book' && !get_config('local_aireader', 'enable_book')) {
            return;
        }

        $scriptpath = $PAGE->url->get_path();
        if (!str_ends_with($scriptpath, "/mod/{$modname}/view.php")) {
            return;
        }

        $modulecontext = \context_module::instance($PAGE->cm->id);
        $canlisten = has_capability('local/aireader:listen', $modulecontext);
        $canmanage = has_capability('local/aireader:manage', $modulecontext);
        if (!$canlisten && !$canmanage) {
            return;
        }

        $chapterid = 0;
        if ($modname === 'book') {
            try {
                $chapterid = self::resolve_book_chapter_for_player(
                    $PAGE->cm,
                    $modulecontext,
                    (int)optional_param('chapterid', 0, PARAM_INT)
                );
            } catch (\Throwable $e) {
                return;
            }
            if ($chapterid <= 0) {
                return;
            }
        }

        $enabled = override_manager::is_enabled((int)$PAGE->cm->id, $chapterid, $modname);
        if (!$enabled && !$canmanage) {
            return;
        }

        $pollinterval = (int)(get_config('local_aireader', 'poll_interval') ?: 5);
        $disclosure = (string)(get_config('local_aireader', 'disclosure')
            ?: get_string('default_disclosure', 'local_aireader'));

        // Build the language menu the player should offer.
        $enabledcodes = asset_manager::enabled_languages();
        $languages = [];
        foreach ($enabledcodes as $code) {
            $languages[] = [
                'code' => $code,
                'name' => openai_translator::language_display_name($code),
            ];
        }
        $defaultlang = current_language();
        if (!in_array($defaultlang, $enabledcodes, true)) {
            $defaultlang = $enabledcodes[0];
        }

        // Resolve the human-readable scope label so the manager-toggle UI can
        // be properly localised: "Turn off for this page" / "...chapter" / "...book".
        $scopekey = 'page';
        if ($modname === 'book') {
            $scopekey = $chapterid > 0 ? 'chapter' : 'book';
        }
        $scopelabel = get_string('scope_' . $scopekey, 'local_aireader');

        $PAGE->requires->js_call_amd('local_aireader/player', 'init', [[
            'cmid'             => (int)$PAGE->cm->id,
            'contextid'        => $modulecontext->id,
            'module'           => $modname,
            'chapterid'        => $chapterid,
            'lang'             => $defaultlang,
            'languages'        => $languages,
            'pollinterval'     => $pollinterval,
            'disclosure'       => $disclosure,
            'enabled'          => $enabled,
            'canmanage'        => $canmanage,
            'sourcelang'       => (string)($GLOBALS['CFG']->lang ?? 'en'),
            'alignmentenabled' => (bool)get_config('local_aireader', 'enable_alignment'),
            'highlightinplace' => (bool)get_config('local_aireader', 'highlight_in_place'),
            'design'           => (string)(get_config('local_aireader', 'player_design') ?: 'full'),
            'accentcolor'      => (string)(get_config('local_aireader', 'player_accent_color') ?: '#f86a01'),
            'autoplay'         => (bool)get_config('local_aireader', 'autoplay_on_expand'),
            'strings'          => self::player_strings($scopelabel),
        ]]);

        $hook->add_html('<div id="local-aireader-mount" data-region="local_aireader-player"></div>');
    }

    /**
     * Collect every user-visible string the player needs into one bag, so the
     * AMD module never has to call core/str at runtime. Keeps the player
     * fully translatable through standard Moodle language packs.
     *
     * @param string $scopelabel Localized scope noun (page / chapter / book)
     *                           used for the manager-toggle messages.
     * @return array<string,string>
     */
    private static function player_strings(string $scopelabel): array {
        return [
            'listentitle'        => get_string('player_listen_title', 'local_aireader'),
            'listenshort'        => get_string('player_listen_short', 'local_aireader'),
            'expand'             => get_string('player_expand', 'local_aireader'),
            'loading'            => get_string('player_loading', 'local_aireader'),
            'loadingaudio'       => get_string('player_loading_audio', 'local_aireader'),
            'ready'              => get_string('player_ready', 'local_aireader'),
            'couldnotload'       => get_string('player_could_not_load', 'local_aireader'),
            'playbackfailed'     => get_string('player_playback_failed', 'local_aireader'),
            'playbackblocked'    => get_string('player_playback_blocked', 'local_aireader'),
            'generationfailed'   => get_string('player_generation_failed', 'local_aireader'),
            'beingprepared'      => get_string('player_being_prepared', 'local_aireader'),
            'queuedforregen'     => get_string('player_queued_for_regen', 'local_aireader'),
            'preparingtranscript' => get_string('player_preparing_transcript', 'local_aireader'),
            'preparinglang'      => get_string('player_preparing_lang', 'local_aireader'),
            'play'               => get_string('player_play', 'local_aireader'),
            'pause'              => get_string('player_pause', 'local_aireader'),
            'skipback'           => get_string('player_skip_back', 'local_aireader'),
            'skipforward'        => get_string('player_skip_forward', 'local_aireader'),
            'speed'              => get_string('player_speed', 'local_aireader'),
            'playbackspeed'      => get_string('player_playback_speed', 'local_aireader'),
            'restart'            => get_string('player_restart', 'local_aireader'),
            'download'           => get_string('player_download', 'local_aireader'),
            'regenerate'         => get_string('player_regenerate', 'local_aireader'),
            'showtranscript'     => get_string('player_show_transcript', 'local_aireader'),
            'transcriptlabel'    => get_string('player_transcript_label', 'local_aireader'),
            'language'           => get_string('player_language', 'local_aireader'),
            'progress'           => get_string('player_progress', 'local_aireader'),
            'offlinedisabled'    => get_string('player_offline_disabled', 'local_aireader'),
            'turnonhere'         => get_string('player_turn_on_here', 'local_aireader', $scopelabel),
            'turnoffhere'        => get_string('player_turn_off_here', 'local_aireader', $scopelabel),
            'offheremsg'         => get_string('player_off_here', 'local_aireader', $scopelabel),
        ];
    }

    /**
     * Resolve the Book chapter the player should target.
     *
     * @param \cm_info|\stdClass $cm Book course module record.
     * @param \context_module $context Book module context.
     * @param int $requestedchapterid Chapter id from the request, or 0.
     * @return int Chapter id, or 0 when the book has no visible chapters.
     */
    public static function resolve_book_chapter_for_player(
        \cm_info|\stdClass $cm,
        \context_module $context,
        int $requestedchapterid
    ): int {
        if ($requestedchapterid > 0) {
            asset_manager::assert_chapter_visible($cm, $requestedchapterid, $context);
            return $requestedchapterid;
        }

        return self::resolve_default_book_chapter((int)$cm->instance, $context);
    }

    /**
     * Best-effort lookup of the first chapter visible to the current user.
     *
     * @param int $bookid The book id.
     * @param \context_module $context Book module context.
     * @return int Chapter id, or 0 when the user has no visible chapter.
     */
    private static function resolve_default_book_chapter(int $bookid, \context_module $context): int {
        global $DB;
        $chapters = $DB->get_records(
            'book_chapters',
            ['bookid' => $bookid],
            'pagenum ASC',
            'id, hidden'
        );
        if (!$chapters) {
            return 0;
        }

        $canviewhidden = has_capability('mod/book:viewhiddenchapters', $context)
            || has_capability('mod/book:edit', $context);
        foreach ($chapters as $chapter) {
            if (empty($chapter->hidden) || $canviewhidden) {
                return (int)$chapter->id;
            }
        }
        return 0;
    }
}
