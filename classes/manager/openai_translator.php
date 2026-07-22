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

use local_aireader\manager\http_guard;

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
            ?: 'gpt-5-mini');
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
        http_guard::assert_safe_url($this->endpoint);

        $systemprompt = self::render_prompt(
            $this->prompt,
            self::language_display_name($source),
            self::language_display_name($target)
        );

        $payload = json_encode(
            self::build_payload($this->model, $systemprompt, $cleantext),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

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
            'CURLOPT_PROTOCOLS'      => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
        ]);

        $response = $curl->post($this->endpoint, $payload);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception(
                'error_translation_http',
                'local_aireader',
                '',
                http_guard::sanitize_error($status, $response)
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
     * Build the chat-completion request body for a translation call,
     * adapting the sampling parameters to the model family.
     *
     * GPT-5-series and o-series reasoning models reject any non-default
     * `temperature`, so it is only sent to models that accept it (the low
     * value keeps translations close to deterministic there). GPT-5 models
     * additionally accept `reasoning_effort`; translation gains nothing from
     * deliberation, so `minimal` keeps latency and cost down.
     *
     * @param string $model Chat-completion model id.
     * @param string $systemprompt Rendered system prompt.
     * @param string $cleantext Source text to translate.
     * @return array JSON-encodable request body.
     */
    public static function build_payload(string $model, string $systemprompt, string $cleantext): array {
        $payload = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $cleantext],
            ],
        ];
        // gpt-5-chat-* is the family's non-reasoning variant: it takes a
        // temperature like gpt-4o and rejects reasoning_effort.
        $isreasoning = (bool)preg_match('/^(gpt-5(?!-chat)|o\d)/i', $model);
        if (!$isreasoning) {
            $payload['temperature'] = 0.2;
        }
        if ($isreasoning && preg_match('/^gpt-5/i', $model)) {
            // 'minimal' is gpt-5-only; o-series models would reject it.
            $payload['reasoning_effort'] = 'minimal';
        }
        return $payload;
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
     * The languages OpenAI's speech models support, keyed by Moodle language
     * code, ordered alphabetically by English display name.
     *
     * This is the single source of truth for the "Languages offered to
     * learners" admin checklist and for {@see language_display_name()}. It
     * mirrors the officially supported language list in OpenAI's speech
     * documentation (Whisper and the TTS models share coverage), split into
     * Moodle locale codes where Moodle has no bare code (zh_cn / zh_tw), plus
     * the regional variants common on Moodle sites (en_gb, pt_br, es_mx, …)
     * which map onto the same underlying model support.
     *
     * OpenAI exposes no API to enumerate supported languages, so this list is
     * maintained by hand — last synced with the OpenAI docs in July 2026.
     * When OpenAI adds a language before this list catches up, admins can
     * enable it immediately via the "Additional language codes" setting; no
     * code change is required.
     *
     * @return array<string,string> Moodle language code => English name.
     */
    public static function supported_languages(): array {
        return [
            'af'    => 'Afrikaans',
            'ar'    => 'Arabic',
            'hy'    => 'Armenian',
            'az'    => 'Azerbaijani',
            'be'    => 'Belarusian',
            'bn'    => 'Bengali',
            'bs'    => 'Bosnian',
            'bg'    => 'Bulgarian',
            'ca'    => 'Catalan',
            'zh_cn' => 'Chinese (Simplified)',
            'zh_tw' => 'Chinese (Traditional)',
            'hr'    => 'Croatian',
            'cs'    => 'Czech',
            'da'    => 'Danish',
            'nl'    => 'Dutch',
            'en'    => 'English',
            'en_gb' => 'English (United Kingdom)',
            'en_us' => 'English (United States)',
            'et'    => 'Estonian',
            'fi'    => 'Finnish',
            'fr'    => 'French',
            'fr_ca' => 'French (Canada)',
            'gl'    => 'Galician',
            'de'    => 'German',
            'el'    => 'Greek',
            'he'    => 'Hebrew',
            'hi'    => 'Hindi',
            'hu'    => 'Hungarian',
            'is'    => 'Icelandic',
            'id'    => 'Indonesian',
            'it'    => 'Italian',
            'ja'    => 'Japanese',
            'kn'    => 'Kannada',
            'kk'    => 'Kazakh',
            'ko'    => 'Korean',
            'lv'    => 'Latvian',
            'lt'    => 'Lithuanian',
            'mk'    => 'Macedonian',
            'ms'    => 'Malay',
            'mi'    => 'Maori',
            'mr'    => 'Marathi',
            'ne'    => 'Nepali',
            'no'    => 'Norwegian',
            'fa'    => 'Persian (Farsi)',
            'pl'    => 'Polish',
            'pt'    => 'Portuguese',
            'pt_br' => 'Portuguese (Brazil)',
            'ro'    => 'Romanian',
            'ru'    => 'Russian',
            'sr'    => 'Serbian',
            'sk'    => 'Slovak',
            'sl'    => 'Slovenian',
            'es'    => 'Spanish',
            'es_co' => 'Spanish (Colombia)',
            'es_mx' => 'Spanish (Mexico)',
            'sw'    => 'Swahili',
            'sv'    => 'Swedish',
            'tl'    => 'Tagalog',
            'ta'    => 'Tamil',
            'th'    => 'Thai',
            'tr'    => 'Turkish',
            'uk'    => 'Ukrainian',
            'ur'    => 'Urdu',
            'vi'    => 'Vietnamese',
            'cy'    => 'Welsh',
        ];
    }

    /**
     * Map a Moodle language code to a human-readable name in English, falling
     * back to the raw code.
     *
     * We don't rely on `get_string('thislanguageint', 'langconfig', null, $code)`
     * because Moodle silently falls back to the site language when the target
     * lang pack isn't installed — which leaves every code looking like
     * "English" on a default install and turns the translation prompt into a
     * no-op (translate English to English). {@see supported_languages()} covers
     * the locales most Moodle sites use; unknown codes pass through unchanged
     * so the model still receives an unambiguous hint.
     *
     * @param string $langcode e.g. 'en', 'es', 'zh_cn'.
     * @return string e.g. 'English', 'Spanish', 'Chinese (Simplified)'.
     */
    public static function language_display_name(string $langcode): string {
        $key = strtolower(str_replace('-', '_', trim($langcode)));
        return self::supported_languages()[$key] ?? $langcode;
    }
}
