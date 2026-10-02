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
 * Course-level "download all narration" collection and packaging.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Collects the ready narration audio a learner may download for a whole course
 * and packages it into a single ZIP.
 *
 * Access control deliberately mirrors {@see local_aireader_pluginfile()} on a
 * per-asset basis: the source activity must be user-visible, the caller must
 * hold `local/aireader:listen` on the module context, narration must be enabled
 * for that scope, and hidden Book chapters require `mod/book:viewhiddenchapters`.
 * Only assets already in the `ready` state are considered — this never queues
 * or triggers generation, so a student action can't run up an OpenAI bill.
 *
 * @package local_aireader
 */
class download_manager {
    /**
     * Whether audio downloads are enabled site-wide.
     *
     * Mirrors the gate the player and pluginfile handler use: the `allow_downloads`
     * setting defaults on, so only an explicit '0' disables downloads.
     *
     * @return bool
     */
    public static function downloads_enabled(): bool {
        return \get_config('local_aireader', 'allow_downloads') !== '0';
    }

    /**
     * The configured "large download" warning threshold, in bytes.
     *
     * @return int Threshold in bytes, or 0 when no warning threshold is set.
     */
    public static function warn_threshold_bytes(): int {
        $mb = (int)\get_config('local_aireader', 'download_warn_threshold_mb');
        if ($mb <= 0) {
            return 0;
        }
        return $mb * 1024 * 1024;
    }

    /**
     * Collect the downloadable narration items for a user across a course.
     *
     * @param \stdClass $course Course record.
     * @param int $userid The user the download is being prepared for.
     * @return array<int,\stdClass> Item objects with keys: assetid, file
     *         (\stored_file), archivename, bytesize, cmid, chapterid, module,
     *         lang, activityname, chaptertitle.
     */
    public static function collect_for_course(\stdClass $course, int $userid): array {
        global $DB;

        if (!self::downloads_enabled()) {
            return [];
        }

        $enabledlangs = asset_manager::enabled_languages();
        $enabledvoices = asset_manager::enabled_voices();
        $defaultvoice = asset_manager::default_voice();
        $modinfo = \get_fast_modinfo($course, $userid);
        $fs = \get_file_storage();

        $assets = $DB->get_records('local_aireader_asset', [
            'courseid' => (int)$course->id,
            'status'   => asset_manager::STATUS_READY,
        ], 'cmid ASC, chapterid ASC, lang ASC');

        // Preload every referenced book chapter in one query rather than
        // looking up title and visibility per asset.
        $chapterids = [];
        foreach ($assets as $asset) {
            if ((int)$asset->chapterid > 0) {
                $chapterids[(int)$asset->chapterid] = true;
            }
        }
        $chapters = $chapterids
            ? $DB->get_records_list('book_chapters', 'id', array_keys($chapterids), '', 'id, bookid, title, hidden')
            : [];

        // Load every asset's stored file in one query. The page lists all
        // languages to build its selector, so a lookup per asset multiplied
        // the queries by the number of languages generated.
        $filesbyasset = [];
        if ($assets) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($assets), SQL_PARAMS_NAMED);
            $params += ['component' => storage::COMPONENT, 'filearea' => storage::FILEAREA, 'dot' => '.'];
            $filerecords = $DB->get_records_select(
                'files',
                "component = :component AND filearea = :filearea AND itemid {$insql} AND filename <> :dot",
                $params,
                'itemid, id'
            );
            foreach ($filerecords as $record) {
                $filesbyasset[(int)$record->itemid] ??= $fs->get_file_instance($record);
            }
        }

        $items = [];
        $usednames = [];
        foreach ($assets as $asset) {
            $module = (string)$asset->module;
            if (!in_array($module, ['page', 'book'], true)) {
                continue;
            }
            if (!in_array((string)$asset->lang, $enabledlangs, true)) {
                continue;
            }
            if (!in_array((string)$asset->voice, $enabledvoices, true)) {
                continue;
            }

            $cmid = (int)$asset->cmid;
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            $cm = $modinfo->cms[$cmid];
            if ($cm->modname !== $module || !$cm->uservisible) {
                continue;
            }

            $context = \context_module::instance($cmid);
            if (!\has_capability('local/aireader:listen', $context, $userid)) {
                continue;
            }

            $chapterid = (int)$asset->chapterid;
            if (!override_manager::is_enabled($cmid, $chapterid, $module)) {
                continue;
            }
            $chapter = $chapterid > 0 ? ($chapters[$chapterid] ?? null) : null;
            if ($module === 'book' && $chapterid > 0 && !self::chapter_visible_for($cm, $chapter, $context, $userid)) {
                continue;
            }

            $file = $filesbyasset[(int)$asset->id] ?? null;
            if (!$file || (int)$file->get_contextid() !== (int)$context->id) {
                continue;
            }

            $chaptertitle = $chapter ? (string)$chapter->title : '';

            $archivename = self::unique_name(
                self::build_archive_name(
                    $course,
                    $cm,
                    $chaptertitle,
                    (string)$asset->lang,
                    (string)$asset->voice,
                    $defaultvoice
                ),
                $usednames
            );

            $items[] = (object)[
                'assetid'      => (int)$asset->id,
                'file'         => $file,
                'archivename'  => $archivename,
                'bytesize'     => (int)$file->get_filesize(),
                'cmid'         => $cmid,
                'chapterid'    => $chapterid,
                'module'       => $module,
                'lang'         => (string)$asset->lang,
                'voice'        => (string)$asset->voice,
                'activityname' => $cm->get_formatted_name(),
                'chaptertitle' => $chaptertitle !== '' ? \format_string($chaptertitle) : '',
            ];
        }

        return $items;
    }

    /**
     * Languages that have downloadable audio, in the site's offered order.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @return string[] Language codes.
     */
    public static function languages_in(array $items): array {
        $present = [];
        foreach ($items as $item) {
            $present[$item->lang] = true;
        }
        return array_values(array_filter(
            asset_manager::enabled_languages(),
            fn(string $lang) => isset($present[$lang])
        ));
    }

    /**
     * Voices that have downloadable audio in one language, default voice first.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @param string $lang Language code.
     * @return string[] Voice ids.
     */
    public static function voices_in(array $items, string $lang): array {
        $present = [];
        foreach ($items as $item) {
            if ($item->lang === $lang) {
                $present[$item->voice] = true;
            }
        }
        $default = asset_manager::default_voice();
        $voices = array_keys($present);
        usort($voices, fn(string $a, string $b) => [$a !== $default, $a] <=> [$b !== $default, $b]);
        return $voices;
    }

    /**
     * Pick the language a course download should contain.
     *
     * A course ZIP used to bundle every language, multiplying its size by the
     * number of languages generated (CPIT-458). Learners now download one
     * language at a time: the one they asked for when it has audio, otherwise
     * their own language as the player would resolve it, otherwise the first
     * language that has any.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @param string $requested Language from the request, or ''.
     * @param string $learnerlang The learner's current Moodle language.
     * @return string|null Language code, or null when nothing is downloadable.
     */
    public static function choose_language(array $items, string $requested, string $learnerlang): ?string {
        $available = self::languages_in($items);
        if (!$available) {
            return null;
        }
        foreach ([$requested, asset_manager::resolve_language($learnerlang)] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        return $available[0];
    }

    /**
     * Pick the voice for a single-language download.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @param string $lang Chosen language.
     * @param string $requested Voice from the request, or ''.
     * @return string|null Voice id, or null when the language has no audio.
     */
    public static function choose_voice(array $items, string $lang, string $requested): ?string {
        $voices = self::voices_in($items, $lang);
        if (!$voices) {
            return null;
        }
        return in_array($requested, $voices, true) ? $requested : $voices[0];
    }

    /**
     * Keep only the items in one language and voice.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @param string $lang Language code.
     * @param string $voice Voice id.
     * @return array Matching items, order preserved.
     */
    public static function filter_items(array $items, string $lang, string $voice): array {
        return array_values(array_filter(
            $items,
            fn(\stdClass $item) => $item->lang === $lang && $item->voice === $voice
        ));
    }

    /**
     * Sum the byte sizes of a collected item list.
     *
     * @param array $items Items from {@see collect_for_course()}.
     * @return int Total bytes.
     */
    public static function total_bytes(array $items): int {
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int)$item->bytesize;
        }
        return $sum;
    }

    /**
     * Package the collected items into a ZIP and stream it to the browser.
     *
     * The ZIP is written to a per-request temp directory and served with
     * {@see send_temp_file()}, which sets download headers, streams the file,
     * and exits. Callers must have already collected the items through
     * {@see collect_for_course()} so access control has been applied.
     *
     * @param \stdClass $course Course record (names the archive).
     * @param array $items Access-checked items to include.
     * @param string $label Optional suffix for the archive name, e.g. the language.
     * @return void This method does not return; send_temp_file() exits.
     */
    public static function serve_zip(\stdClass $course, array $items, string $label = ''): void {
        $packer = \get_file_packer('application/zip');

        $forzip = [];
        foreach ($items as $item) {
            $forzip[$item->archivename] = $item->file;
        }

        $zipname = \clean_filename(
            \format_string($course->shortname) . ' - ' . \get_string('download_zip_suffix', 'local_aireader')
            . ($label !== '' ? ' (' . $label . ')' : '')
        ) . '.zip';

        $temppath = \make_request_directory() . '/' . $zipname;
        $packer->archive_to_pathname($forzip, $temppath);

        \send_temp_file($temppath, $zipname);
    }

    /**
     * Non-throwing companion to {@see asset_manager::assert_chapter_visible()},
     * evaluated against a preloaded chapter record.
     *
     * @param \cm_info $cm Book course module.
     * @param \stdClass|null $chapter Preloaded book_chapters row (id, bookid,
     *                       title, hidden), or null when the chapter no longer exists.
     * @param \context_module $context Module context.
     * @param int $userid User the visibility is evaluated for.
     * @return bool Whether the chapter is visible to the user.
     */
    private static function chapter_visible_for(
        \cm_info $cm,
        ?\stdClass $chapter,
        \context_module $context,
        int $userid
    ): bool {
        if (!$chapter || (int)$chapter->bookid !== (int)$cm->instance) {
            return false;
        }
        if (!empty($chapter->hidden) && !\has_capability('mod/book:viewhiddenchapters', $context, $userid)) {
            return false;
        }
        return true;
    }

    /**
     * Build a human-readable archive filename mirroring the pluginfile handler:
     * "Course - Activity[ - Chapter] (lang).mp3". Non-default voices get a
     * voice marker — "(lang, Voice)" — so a multi-voice download does not
     * collapse into "(2)"-suffixed duplicates.
     *
     * @param \stdClass $course Course record.
     * @param \cm_info $cm Course module.
     * @param string $chaptertitle Raw chapter title, or '' for non-chapter assets.
     * @param string $lang Language code.
     * @param string $voice Voice id the asset was generated with.
     * @param string $defaultvoice The site default voice id.
     * @return string Cleaned filename.
     */
    private static function build_archive_name(
        \stdClass $course,
        \cm_info $cm,
        string $chaptertitle,
        string $lang,
        string $voice,
        string $defaultvoice
    ): string {
        $bits = [
            \format_string($course->shortname),
            $cm->get_formatted_name(),
        ];
        if ($chaptertitle !== '') {
            $bits[] = \format_string($chaptertitle);
        }
        $label = implode(' - ', array_filter($bits));
        $marker = $voice !== '' && $voice !== $defaultvoice
            ? $lang . ', ' . openai_client::voice_display_name($voice)
            : $lang;
        return \clean_filename($label . ' (' . $marker . ').mp3');
    }

    /**
     * Ensure an archive entry name is unique within one ZIP, appending
     * " (2)", " (3)", … before the extension on collision.
     *
     * @param string $name Proposed archive filename.
     * @param array $used Map of lowercased names already taken, updated by reference.
     * @return string A name not present in $used.
     */
    private static function unique_name(string $name, array &$used): string {
        $candidate = $name;
        $counter = 1;
        while (isset($used[\core_text::strtolower($candidate)])) {
            $counter++;
            $dot = strrpos($name, '.');
            if ($dot === false) {
                $candidate = $name . " ({$counter})";
            } else {
                $candidate = substr($name, 0, $dot) . " ({$counter})" . substr($name, $dot);
            }
        }
        $used[\core_text::strtolower($candidate)] = true;
        return $candidate;
    }
}
