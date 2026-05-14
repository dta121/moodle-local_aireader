<?php
namespace local_aireader;

use local_aireader\manager\asset_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers that invalidate cached audio when source content changes.
 *
 * Note: the sourcehash recomputed on every view is the definitive invalidation
 * check. These observers are an optimisation: they mark assets stale early so
 * regeneration can be queued ahead of the next learner visit.
 */
class observer {

    public static function course_module_updated(\core\event\course_module_updated $event): void {
        self::invalidate_for_cm((int)$event->objectid, $event->other['modulename'] ?? null);
    }

    public static function course_module_created(\core\event\course_module_created $event): void {
        // Nothing to invalidate yet; first view triggers generation.
    }

    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        asset_manager::purge_cm((int)$event->objectid);
    }

    public static function book_chapter_updated(\core\event\base $event): void {
        $chapterid = (int)$event->objectid;
        asset_manager::mark_chapter_stale($chapterid);
    }

    public static function book_chapter_deleted(\core\event\base $event): void {
        $chapterid = (int)$event->objectid;
        asset_manager::purge_chapter($chapterid);
    }

    private static function invalidate_for_cm(int $cmid, ?string $modulename): void {
        if ($modulename !== 'page' && $modulename !== 'book') {
            return;
        }
        asset_manager::mark_cm_stale($cmid);

        if (get_config('local_aireader', 'auto_generate_on_save')) {
            asset_manager::queue_regeneration_for_cm($cmid);
        }
    }
}
