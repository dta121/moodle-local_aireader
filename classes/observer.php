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
 * Event observers that invalidate cached audio when source content changes.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader;

use local_aireader\manager\asset_manager;
use local_aireader\manager\override_manager;

/**
 * Observers that mark cached assets stale when source content changes.
 *
 * The sourcehash recomputed on every view is the authoritative invalidation
 * check; these observers are an optimisation that mark assets stale early so
 * regeneration can be queued ahead of the next learner visit.
 *
 * @package local_aireader
 */
class observer {
    /**
     * Mark assets stale when a course module is updated.
     *
     * @param \core\event\course_module_updated $event
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        self::invalidate_for_cm((int)$event->objectid, $event->other['modulename'] ?? null);
    }

    /**
     * No-op on create; first view triggers generation.
     *
     * @param \core\event\course_module_created $event
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        unset($event);
    }

    /**
     * Purge metadata, stored files, and overrides when the source module is deleted.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        $cmid = (int)$event->objectid;
        asset_manager::purge_cm($cmid);
        override_manager::purge_cm($cmid);
    }

    /**
     * Mark a chapter's cached audio stale when the chapter is updated.
     *
     * @param \core\event\base $event
     */
    public static function book_chapter_updated(\core\event\base $event): void {
        $chapterid = (int)$event->objectid;
        asset_manager::mark_chapter_stale($chapterid);
    }

    /**
     * Purge a chapter's cached audio and override when the chapter is deleted.
     *
     * @param \core\event\base $event
     */
    public static function book_chapter_deleted(\core\event\base $event): void {
        $chapterid = (int)$event->objectid;
        asset_manager::purge_chapter($chapterid);
        override_manager::purge_chapter($chapterid);
    }

    /**
     * Invalidate cached audio for a course module if it is a supported type.
     *
     * @param int $cmid Course module id.
     * @param string|null $modulename Module name, e.g. "page" or "book".
     */
    private static function invalidate_for_cm(int $cmid, ?string $modulename): void {
        if ($modulename !== 'page' && $modulename !== 'book') {
            return;
        }
        asset_manager::mark_cm_stale($cmid);

        if (!get_config('local_aireader', 'auto_generate_on_save')) {
            return;
        }

        asset_manager::queue_regeneration_for_cm($cmid);

        // Eager mode: also pre-generate every enabled non-default language so
        // the first learner in (say) Spanish doesn't have to wait for synthesis.
        if (get_config('local_aireader', 'eager_languages_on_save')) {
            asset_manager::queue_eager_language_generation_for_cm($cmid);
        }
    }
}
