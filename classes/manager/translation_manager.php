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
 * Cached translations of cleaned source text for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Read/write helpers for the local_aireader_translation table.
 *
 * Translations are keyed on `(sha256(cleantext), targetlang, model)` so that
 * one translation pass serves every voice and TTS model: five voices of
 * Spanish narration for a page = one translation call + five TTS calls.
 *
 * @package local_aireader
 */
class translation_manager {

    /**
     * Compute the lookup key for a piece of cleaned source text.
     *
     * @param string $cleantext
     * @return string 64-char hex SHA-256.
     */
    public static function compute_texthash(string $cleantext): string {
        return hash('sha256', $cleantext);
    }

    /**
     * Return the cached translation for this (text, target language, model) tuple, or null.
     *
     * @param string $texthash {@see compute_texthash()}
     * @param string $targetlang Moodle language code (e.g. 'es', 'fr', 'zh_cn').
     * @param string $model Translation model id.
     * @return string|null Translated text, or null if no cache hit.
     */
    public static function get(string $texthash, string $targetlang, string $model): ?string {
        global $DB;
        $row = $DB->get_record('local_aireader_translation', [
            'texthash'   => $texthash,
            'targetlang' => $targetlang,
            'model'      => $model,
        ], 'translated');
        return $row ? (string)$row->translated : null;
    }

    /**
     * Upsert a translation. If a row already exists for this key, the stored
     * text is refreshed (rare — implies the previous translation was wrong).
     *
     * @param string $cleantext Original source text.
     * @param string $sourcelang Moodle language code of the source.
     * @param string $targetlang Moodle language code of the translation.
     * @param string $model Translation model id used.
     * @param string $translated Translated text to store.
     */
    public static function store(
        string $cleantext,
        string $sourcelang,
        string $targetlang,
        string $model,
        string $translated
    ): void {
        global $DB;
        $now = time();
        $texthash = self::compute_texthash($cleantext);
        $existing = $DB->get_record('local_aireader_translation', [
            'texthash'   => $texthash,
            'targetlang' => $targetlang,
            'model'      => $model,
        ], 'id');
        if ($existing) {
            $DB->update_record('local_aireader_translation', (object)[
                'id'           => $existing->id,
                'translated'   => $translated,
                'timemodified' => $now,
            ]);
            return;
        }
        $DB->insert_record('local_aireader_translation', (object)[
            'texthash'     => $texthash,
            'sourcelang'   => $sourcelang,
            'targetlang'   => $targetlang,
            'model'        => $model,
            'translated'   => $translated,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Return the cached translation, or call $translator to produce one and store it.
     *
     * @param string   $cleantext Source text.
     * @param string   $sourcelang Source Moodle language code.
     * @param string   $targetlang Target Moodle language code.
     * @param string   $model Translation model id.
     * @param callable $translator function($cleantext, $sourcelang, $targetlang, $model): string
     * @return string Translated text. If source == target, returns $cleantext untouched.
     */
    public static function get_or_translate(
        string $cleantext,
        string $sourcelang,
        string $targetlang,
        string $model,
        callable $translator
    ): string {
        if (self::is_same_language($sourcelang, $targetlang)) {
            return $cleantext;
        }
        $texthash = self::compute_texthash($cleantext);
        $cached = self::get($texthash, $targetlang, $model);
        if ($cached !== null) {
            return $cached;
        }
        $translated = (string)$translator($cleantext, $sourcelang, $targetlang, $model);
        if ($translated === '') {
            throw new \moodle_exception('error_translation_empty', 'local_aireader');
        }
        self::store($cleantext, $sourcelang, $targetlang, $model, $translated);
        return $translated;
    }

    /**
     * Whether two Moodle language codes refer to the same underlying language.
     *
     * Moodle uses codes like 'en', 'en_us', 'es', 'zh_cn'. Variants of the
     * same base language share a translation — narrating en_us content for
     * an en_gb learner does not need a separate translation pass.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function is_same_language(string $a, string $b): bool {
        $norm = static fn(string $code): string => strtolower(preg_replace('/[_-].*$/', '', $code));
        return $norm($a) === $norm($b);
    }
}
