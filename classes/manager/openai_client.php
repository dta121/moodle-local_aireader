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
 * OpenAI Audio (speech) client for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Minimal OpenAI Audio (speech) client.
 *
 * Endpoint: POST {endpoint} with JSON body {model, voice, input, instructions, format}.
 * Response: raw audio bytes (mp3).
 *
 * @package local_aireader
 */
class openai_client {

    /** @var string Bearer API key. */
    private $apikey;
    /** @var string Endpoint URL. */
    private $endpoint;

    /**
     * Construct a client, optionally overriding the configured key/endpoint.
     *
     * @param string|null $apikey
     * @param string|null $endpoint
     */
    public function __construct(?string $apikey = null, ?string $endpoint = null) {
        $this->apikey   = $apikey ?? (string)get_config('local_aireader', 'openai_api_key');
        $this->endpoint = $endpoint ?? (string)(get_config('local_aireader', 'openai_endpoint')
            ?: 'https://api.openai.com/v1/audio/speech');
    }

    /**
     * Synthesize a single chunk and return raw mp3 bytes.
     *
     * @param string $text Input text to read.
     * @param string $model TTS model id.
     * @param string $voice Voice name.
     * @param string $instructions Narration style instructions.
     * @return string Raw mp3 bytes.
     * @throws \moodle_exception On transport or API error.
     */
    public function synthesize(string $text, string $model, string $voice, string $instructions): string {
        if ($this->apikey === '') {
            throw new \moodle_exception('error_no_apikey', 'local_aireader');
        }

        $payload = json_encode([
            'model'        => $model,
            'voice'        => $voice,
            'input'        => $text,
            'instructions' => $instructions,
            'format'       => 'mp3',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
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
                'error_tts_http',
                'local_aireader',
                '',
                (object)['status' => $status, 'body' => $snippet]
            );
        }

        if (!is_string($response) || $response === '') {
            throw new \moodle_exception('error_tts_empty', 'local_aireader');
        }
        return $response;
    }

    /**
     * Split input into chunks at sentence boundaries, preferring to stay under $maxchars.
     *
     * @param string $text Input text.
     * @param int $maxchars Soft maximum chars per chunk.
     * @return string[]
     */
    public static function chunk_text(string $text, int $maxchars): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $maxchars) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[\.\?\!])\s+/u', $text) ?: [$text];
        $chunks = [];
        $buffer = '';
        foreach ($sentences as $sentence) {
            $candidate = $buffer === '' ? $sentence : ($buffer . ' ' . $sentence);
            if (mb_strlen($candidate) > $maxchars && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $sentence;
            } else {
                $buffer = $candidate;
            }
            while (mb_strlen($buffer) > $maxchars) {
                $cut = mb_strrpos(mb_substr($buffer, 0, $maxchars), ' ') ?: $maxchars;
                $chunks[] = trim(mb_substr($buffer, 0, $cut));
                $buffer = trim(mb_substr($buffer, $cut));
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        return $chunks;
    }
}
