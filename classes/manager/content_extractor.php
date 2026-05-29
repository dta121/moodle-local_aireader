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
        $cm = \get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        $html = \file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $context->id,
            'mod_page',
            'content',
            $page->revision
        );

        return [
            'title'    => \format_string($page->name),
            'subtitle' => null,
            'text'     => self::html_to_speech_text($html, \format_string($page->name)),
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
        $cm = \get_coursemodule_from_id('book', $cmid, 0, false, MUST_EXIST);
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $chapter = $DB->get_record(
            'book_chapters',
            ['id' => $chapterid, 'bookid' => $book->id],
            '*',
            MUST_EXIST
        );

        $context = \context_module::instance($cm->id);
        $html = \file_rewrite_pluginfile_urls(
            $chapter->content,
            'pluginfile.php',
            $context->id,
            'mod_book',
            'chapter',
            $chapter->id
        );

        $booktitle = \format_string($book->name);
        $chaptertitle = \format_string($chapter->title);

        return [
            'title'    => $booktitle,
            'subtitle' => $chaptertitle,
            'text'     => self::html_to_speech_text($html, $booktitle, $chaptertitle),
        ];
    }

    /**
     * Convert HTML into clean narration-ready plain text.
     *
     * Removes script/style/nav/buttons, replaces embedded videos with a short
     * spoken cue ("A video appears here..."), inserts sentence breaks at headings,
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

        // First pass: replace media embeds with narration-friendly placeholders.
        $html = self::replace_media_embeds($html);

        // Strip elements that should never be read aloud.
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#<(script|style|nav|button|form|noscript)[^>]*>.*?</\1>#is', '', $html);

        // Promote block boundaries to explicit sentence breaks so stripping tags
        // doesn't fuse paragraphs together.
        $blockbreaks = ['</p>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>',
                        '</tr>', '<br>', '<br/>', '<br />'];
        $html = str_ireplace($blockbreaks, '. ', $html);

        $text = \html_to_text($html, 0, false);

        // Strip raw URLs that html_to_text may have left as bracketed references.
        $text = preg_replace('#\[(https?://\S+)\]#i', '', $text);
        $text = preg_replace('#https?://\S+#i', '', $text);

        // Collapse repeated punctuation produced by the block-break substitutions above.
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

    /**
     * Replace embedded videos (and other rich media) with a short spoken cue.
     *
     * Uses DOMDocument for robust parsing; falls back to a regex pass if libxml
     * isn't available. Each replaced node becomes a text sentence so the
     * downstream block-break and whitespace collapse passes treat it cleanly.
     *
     * @param string $html
     * @return string
     */
    private static function replace_media_embeds(string $html): string {
        if (!class_exists(\DOMDocument::class)) {
            return self::replace_media_embeds_regex($html);
        }

        $dom = new \DOMDocument();
        $libxmlprev = libxml_use_internal_errors(true);
        // Wrap with a UTF-8 hint so DOMDocument doesn't mangle accents.
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $html . '</div>';
        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlprev);
        if (!$loaded) {
            return self::replace_media_embeds_regex($html);
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//iframe | //video | //embed | //object | //audio');
        // Replace in reverse so DOM mutations don't invalidate the live NodeList.
        $tomutate = [];
        foreach ($nodes as $node) {
            $tomutate[] = $node;
        }
        foreach (array_reverse($tomutate) as $node) {
            $title = trim((string)$node->getAttribute('title'))
                ?: trim((string)$node->getAttribute('aria-label'))
                ?: trim((string)$node->getAttribute('data-title'));
            $src = trim((string)$node->getAttribute('src'));
            $mediakind = self::classify_media_node($node->nodeName, $src);
            $description = self::describe_media($mediakind, $title);
            $replacement = $dom->createTextNode(' ' . $description . ' ');
            $node->parentNode->replaceChild($replacement, $node);
        }

        // The saveHTML on the wrapper div yields the modified inner HTML.
        $wrappernode = $dom->getElementsByTagName('div')->item(0);
        if (!$wrappernode) {
            return $html;
        }
        $innerhtml = '';
        foreach ($wrappernode->childNodes as $child) {
            $innerhtml .= $dom->saveHTML($child);
        }
        return $innerhtml;
    }

    /**
     * Cheap fallback when DOMDocument isn't available.
     *
     * @param string $html
     * @return string
     */
    private static function replace_media_embeds_regex(string $html): string {
        $pattern = '#<(iframe|video|audio|embed|object)\b[^>]*>.*?</\1>#is';
        $html = preg_replace_callback($pattern, static function ($m) {
            $tag = strtolower($m[1]);
            $opening = $m[0];
            $titleattr = '';
            if (preg_match('#\btitle\s*=\s*"([^"]*)"#i', $opening, $tm)) {
                $titleattr = $tm[1];
            }
            $srcattr = '';
            if (preg_match('#\bsrc\s*=\s*"([^"]*)"#i', $opening, $sm)) {
                $srcattr = $sm[1];
            }
            return ' ' . self::describe_media(self::classify_media_kind($tag, $srcattr), $titleattr) . ' ';
        }, $html);
        // Also catch self-closing tags.
        $html = preg_replace_callback('#<(iframe|video|audio|embed)\b[^>]*/>#is', static function ($m) {
            $tag = strtolower($m[1]);
            $opening = $m[0];
            $titleattr = '';
            if (preg_match('#\btitle\s*=\s*"([^"]*)"#i', $opening, $tm)) {
                $titleattr = $tm[1];
            }
            $srcattr = '';
            if (preg_match('#\bsrc\s*=\s*"([^"]*)"#i', $opening, $sm)) {
                $srcattr = $sm[1];
            }
            return ' ' . self::describe_media(self::classify_media_kind($tag, $srcattr), $titleattr) . ' ';
        }, $html);
        return $html;
    }

    /**
     * Classify a DOM media node into a short kind label used by the description templates.
     *
     * @param string $tagname HTML tag name.
     * @param string $src Value of the src attribute (may be empty).
     * @return string One of: video, audio, embed.
     */
    private static function classify_media_node(string $tagname, string $src): string {
        return self::classify_media_kind(strtolower($tagname), $src);
    }

    /**
     * Shared classification logic used by both DOM and regex paths.
     *
     * @param string $tag
     * @param string $src
     * @return string
     */
    private static function classify_media_kind(string $tag, string $src): string {
        if ($tag === 'audio') {
            return 'audio';
        }
        if ($tag === 'video') {
            return 'video';
        }
        if ($tag === 'iframe' || $tag === 'embed' || $tag === 'object') {
            $videohosts = '#(youtube\.com|youtu\.be|vimeo\.com|wistia|kaltura|loom\.com|panopto|brightcove)#i';
            if ($src !== '' && preg_match($videohosts, $src)) {
                return 'video';
            }
            return 'embed';
        }
        return 'embed';
    }

    /**
     * Render a short spoken cue for a media kind, optionally including the title.
     *
     * @param string $kind One of: video, audio, embed.
     * @param string $title Optional human-readable title (from title/aria-label/data-title).
     * @return string A sentence ending in a period; designed to flow inside surrounding prose.
     */
    private static function describe_media(string $kind, string $title): string {
        $title = trim(preg_replace('/\s+/', ' ', $title));
        $key = "media_cue_{$kind}" . ($title !== '' ? '_titled' : '');
        if (!\get_string_manager()->string_exists($key, 'local_aireader')) {
            $key = $title !== '' ? 'media_cue_video_titled' : 'media_cue_video';
        }
        return \get_string($key, 'local_aireader', ['title' => $title]);
    }
}
