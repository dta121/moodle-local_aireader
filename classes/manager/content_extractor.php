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
 * Extracts speech-ready plain text from supported activities.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Extract clean plain text from a supported activity for TTS input.
 *
 * @package local_aireader
 */
class content_extractor {

    /**
     * Extract narration-ready text and titles for a supported activity.
     *
     * @param string $module Module name (page or book).
     * @param int $cmid Course module id.
     * @param int|null $chapterid Book chapter id, or null for non-book modules.
     * @return array Shape: ['title' => string, 'subtitle' => ?string, 'text' => string]
     */
    public static function extract(string $module, int $cmid, ?int $chapterid = null): array {
        switch ($module) {
            case 'page':
                return self::extract_page($cmid);
            case 'book':
                if ($chapterid === null) {
                    throw new \coding_exception('chapterid required for mod_book');
                }
                return self::extract_book_chapter($cmid, $chapterid);
            default:
                throw new \coding_exception("Unsupported module: {$module}");
        }
    }

    /**
     * Extract narration text for a mod_page instance.
     *
     * @param int $cmid Course module id.
     * @return array Shape matches {@see extract()}.
     */
    private static function extract_page(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        $html = file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $context->id,
            'mod_page',
            'content',
            $page->revision
        );

        return [
            'title'    => format_string($page->name),
            'subtitle' => null,
            'text'     => self::html_to_speech_text($html, format_string($page->name)),
        ];
    }

    /**
     * Extract narration text for one chapter of a mod_book instance.
     *
     * @param int $cmid Course module id of the book.
     * @param int $chapterid The chapter to read.
     * @return array Shape matches {@see extract()}.
     */
    private static function extract_book_chapter(int $cmid, int $chapterid): array {
        global $DB;
        $cm = get_coursemodule_from_id('book', $cmid, 0, false, MUST_EXIST);
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $chapter = $DB->get_record(
            'book_chapters',
            ['id' => $chapterid, 'bookid' => $book->id],
            '*',
            MUST_EXIST
        );

        $context = \context_module::instance($cm->id);
        $html = file_rewrite_pluginfile_urls(
            $chapter->content,
            'pluginfile.php',
            $context->id,
            'mod_book',
            'chapter',
            $chapter->id
        );

        $booktitle = format_string($book->name);
        $chaptertitle = format_string($chapter->title);

        return [
            'title'    => $booktitle,
            'subtitle' => $chaptertitle,
            'text'     => self::html_to_speech_text($html, $booktitle, $chaptertitle),
        ];
    }

    /**
     * Convert HTML into clean narration-ready plain text.
     *
     * Removes script/style/nav/buttons, inserts sentence breaks at headings,
     * paragraphs, and list items, collapses whitespace, and strips raw URLs.
     *
     * @param string $html The HTML source from the activity.
     * @param string $title Resource title to prepend.
     * @param string|null $subtitle Optional secondary title (e.g. chapter title).
     * @return string Speech-ready plain text.
     */
    public static function html_to_speech_text(string $html, string $title, ?string $subtitle = null): string {
        if (trim($html) === '') {
            return trim($title . ($subtitle ? '. ' . $subtitle : ''));
        }

        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#<(script|style|nav|button|form|noscript)[^>]*>.*?</\1>#is', '', $html);

        $blockbreaks = ['</p>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>',
                        '</tr>', '<br>', '<br/>', '<br />'];
        $html = str_ireplace($blockbreaks, '. ', $html);

        $text = html_to_text($html, 0, false);

        $text = preg_replace('#\[(https?://\S+)\]#i', '', $text);
        $text = preg_replace('#https?://\S+#i', '', $text);

        $text = preg_replace('/\s*\.\s*(\.\s*)+/', '. ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = preg_replace('/\n{2,}/', "\n\n", $text);

        $header = trim($title);
        if ($subtitle !== null && $subtitle !== '') {
            $header .= '. ' . trim($subtitle);
        }
        $header .= '.';

        return trim($header . "\n\n" . trim($text));
    }
}
