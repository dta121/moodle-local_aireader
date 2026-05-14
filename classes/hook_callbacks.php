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
            $chapterid = (int)optional_param('chapterid', 0, PARAM_INT);
            if ($chapterid === 0) {
                $chapterid = (int)self::resolve_default_book_chapter($PAGE->cm->instance);
            }
        }

        $enabled = override_manager::is_enabled((int)$PAGE->cm->id, $chapterid, $modname);
        if (!$enabled && !$canmanage) {
            return;
        }

        $pollinterval = (int)(get_config('local_aireader', 'poll_interval') ?: 5);
        $disclosure = (string)(get_config('local_aireader', 'disclosure')
            ?: get_string('default_disclosure', 'local_aireader'));

        $PAGE->requires->js_call_amd('local_aireader/player', 'init', [[
            'cmid'          => (int)$PAGE->cm->id,
            'contextid'     => $modulecontext->id,
            'module'        => $modname,
            'chapterid'     => $chapterid,
            'lang'          => current_language(),
            'pollinterval'  => $pollinterval,
            'disclosure'    => $disclosure,
            'enabled'       => $enabled,
            'canmanage'     => $canmanage,
        ]]);

        $hook->add_html('<div id="local-aireader-mount" data-region="local_aireader-player"></div>');
    }

    /**
     * Best-effort lookup of the first visible chapter for a book instance.
     *
     * @param int $bookid The book id.
     * @return int Chapter id, or 0 when the book has no visible chapters.
     */
    private static function resolve_default_book_chapter(int $bookid): int {
        global $DB;
        $chapter = $DB->get_records(
            'book_chapters',
            ['bookid' => $bookid, 'hidden' => 0],
            'pagenum ASC',
            'id',
            0,
            1
        );
        if (!$chapter) {
            return 0;
        }
        $row = reset($chapter);
        return (int)$row->id;
    }
}
