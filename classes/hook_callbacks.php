<?php
namespace local_aireader;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks that inject the player on supported resource views.
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

        // Only on resource view pages.
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

        // Restrict to the actual "view" script for each module.
        $scriptpath = $PAGE->url->get_path();
        if (!str_ends_with($scriptpath, "/mod/{$modname}/view.php")) {
            return;
        }

        // Capability gate: don't render player for users who couldn't listen anyway.
        $modulecontext = \context_module::instance($PAGE->cm->id);
        if (!has_capability('local/aireader:listen', $modulecontext)) {
            return;
        }

        $chapterid = null;
        if ($modname === 'book') {
            $chapterid = optional_param('chapterid', 0, PARAM_INT) ?: null;
            // mod_book defaults to the first chapter when no id given; resolve it.
            if ($chapterid === null) {
                $chapterid = self::resolve_default_book_chapter($PAGE->cm->instance);
            }
        }

        $pollinterval = (int)(get_config('local_aireader', 'poll_interval') ?: 5);
        $disclosure = (string)(get_config('local_aireader', 'disclosure')
            ?: get_string('default_disclosure', 'local_aireader'));

        $PAGE->requires->js_call_amd('local_aireader/player', 'init', [[
            'cmid'         => (int)$PAGE->cm->id,
            'contextid'    => $modulecontext->id,
            'module'       => $modname,
            'chapterid'    => $chapterid,
            'lang'         => current_language(),
            'pollinterval' => $pollinterval,
            'disclosure'   => $disclosure,
            'canregenerate' => has_capability('local/aireader:manage', $modulecontext),
        ]]);

        // The JS mounts into this container, which the module places at the top of the body.
        $hook->add_html('<div id="local-aireader-mount" data-region="local_aireader-player"></div>');
    }

    /**
     * Best-effort lookup of the first visible chapter for a book instance.
     */
    private static function resolve_default_book_chapter(int $bookid): ?int {
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
            return null;
        }
        $row = reset($chapter);
        return (int)$row->id;
    }
}
