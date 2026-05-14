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
 * OpenAI chat-completion client used to translate cleaned narration text.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Thin translator backed by OpenAI's /v1/chat/completions endpoint.
 *
 * The same API key as the TTS client is reused (`local_aireader/openai_api_key`).
 * Translation model, endpoint and system prompt are independent settings so a
 * proxy or alternative chat-completion gateway can be wired in without touching
 * speech configuration.
 *
 * @package local_aireader
 */
class openai_translator {
    /** @var string */
    private $apikey;
    /** @var string */
    private $endpoint;
    /** @var string */
    private $model;
    /** @var string Templated system prompt; may contain {source} / {target} placeholders. */
    private $prompt;

    /**
     * Construct a translator, optionally overriding configured values.
     *
     * @param string|null $apikey
     * @param string|null $endpoint
     * @param string|null $model
     * @param string|null $prompt
     */
    public function __construct(
        ?string $apikey = null,
        ?string $endpoint = null,
        ?string $model = null,
        ?string $prompt = null
    ) {
        $this->apikey = $apikey ?? (string)get_config('local_aireader', 'openai_api_key');
        $this->endpoint = $endpoint ?? (string)(get_config('local_aireader', 'translation_endpoint')
            ?: 'https://api.openai.com/v1/chat/completions');
        $this->model = $model ?? (string)(get_config('local_aireader', 'translation_model')
            ?: 'gpt-4o-mini');
        $this->prompt = $prompt ?? (string)(get_config('local_aireader', 'translation_prompt')
            ?: get_string('default_translation_prompt', 'local_aireader'));
    }

    /**
     * Translate cleaned narration text from $source to $target. Returns the
     * translated text only — no surrounding commentary.
     *
     * @param string $cleantext
     * @param string $source Moodle language code.
     * @param string $target Moodle language code.
     * @return string
     * @throws \moodle_exception on transport, API, or empty-result errors.
     */
    public function translate(string $cleantext, string $source, string $target): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if ($this->apikey === '') {
            throw new \moodle_exception('error_no_apikey', 'local_aireader');
        }

        $systemprompt = self::render_prompt(
            $this->prompt,
            self::language_display_name($source),
            self::language_display_name($target)
        );

        $payload = json_encode([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $cleantext],
            ],
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 120,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_RETURNTRANSFER' => 1,
        ]);

        $response = $curl->post($this->endpoint, $payload);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        if ($status < 200 || $status >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 500) : '';
            throw new \moodle_exception(
                'error_translation_http',
                'local_aireader',
                '',
                (object)['status' => $status, 'body' => $snippet]
            );
        }

        $decoded = json_decode((string)$response, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';
        $text = trim((string)$text);
        if ($text === '') {
            throw new \moodle_exception('error_translation_empty', 'local_aireader');
        }
        return $text;
    }

    /**
     * Apply {source} / {target} placeholders to the prompt template.
     *
     * @param string $template
     * @param string $source Human-readable source language name.
     * @param string $target Human-readable target language name.
     * @return string
     */
    public static function render_prompt(string $template, string $source, string $target): string {
        return strtr($template, [
            '{source}' => $source,
            '{target}' => $target,
        ]);
    }

    /**
     * Map a Moodle language code to a human-readable name in English, falling
     * back to the raw code.
     *
     * We don't rely on `get_string('thislanguageint', 'langconfig', null, $code)`
     * because Moodle silently falls back to the site language when the target
     * lang pack isn't installed — which leaves every code looking like
     * "English" on a default install and turns the translation prompt into a
     * no-op (translate English to English). A small embedded table covers the
     * locales most Moodle sites use; unknown codes pass through unchanged so
     * the model still receives an unambiguous hint.
     *
     * @param string $langcode e.g. 'en', 'es', 'zh_cn'.
     * @return string e.g. 'English', 'Spanish', 'Chinese (Simplified)'.
     */
    public static function language_display_name(string $langcode): string {
        static $map = [
            'en'    => 'English',
            'en_us' => 'English (United States)',
            'en_gb' => 'English (United Kingdom)',
            'es'    => 'Spanish',
            'es_mx' => 'Spanish (Mexico)',
            'es_co' => 'Spanish (Colombia)',
            'fr'    => 'French',
            'fr_ca' => 'French (Canada)',
            'de'    => 'German',
            'it'    => 'Italian',
            'pt'    => 'Portuguese',
            'pt_br' => 'Portuguese (Brazil)',
            'nl'    => 'Dutch',
            'pl'    => 'Polish',
            'ru'    => 'Russian',
            'uk'    => 'Ukrainian',
            'tr'    => 'Turkish',
            'ar'    => 'Arabic',
            'he'    => 'Hebrew',
            'fa'    => 'Persian (Farsi)',
            'hi'    => 'Hindi',
            'bn'    => 'Bengali',
            'ur'    => 'Urdu',
            'ja'    => 'Japanese',
            'ko'    => 'Korean',
            'vi'    => 'Vietnamese',
            'th'    => 'Thai',
            'id'    => 'Indonesian',
            'ms'    => 'Malay',
            'tl'    => 'Tagalog',
            'sw'    => 'Swahili',
            'zh_cn' => 'Chinese (Simplified)',
            'zh_tw' => 'Chinese (Traditional)',
            'el'    => 'Greek',
            'sv'    => 'Swedish',
            'no'    => 'Norwegian',
            'da'    => 'Danish',
            'fi'    => 'Finnish',
            'cs'    => 'Czech',
            'sk'    => 'Slovak',
            'hu'    => 'Hungarian',
            'ro'    => 'Romanian',
            'bg'    => 'Bulgarian',
            'hr'    => 'Croatian',
            'sr'    => 'Serbian',
            'sl'    => 'Slovenian',
            'et'    => 'Estonian',
            'lv'    => 'Latvian',
            'lt'    => 'Lithuanian',
        ];
        $key = strtolower(str_replace('-', '_', trim($langcode)));
        return $map[$key] ?? $langcode;
    }
}
