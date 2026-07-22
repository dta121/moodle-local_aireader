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

use local_aireader\manager\http_guard;

/**
 * Minimal OpenAI Audio (speech) client.
 *
 * Endpoint: POST {endpoint} with JSON body {model, voice, input, instructions, format}.
 * Response: raw audio bytes (mp3).
 *
 * @package local_aireader
 */
class openai_client {
    /** @var int Safe fallback when the configured chunk size is invalid (chars). */
    public const DEFAULT_CHUNK_SIZE = 3800;

    /**
     * @var int Per-chunk token ceiling.
     *
     * The `gpt-4o-mini-tts` model rejects input over 2000 tokens; older
     * `tts-1`/`tts-1-hd` models cap on 4096 characters instead. We apply both
     * caps so a chunk that is comfortably under the character limit but
     * token-dense (e.g. CJK after translation) is still split. 1800 leaves
     * headroom under the 2000-token hard limit.
     */
    public const DEFAULT_MAX_TOKENS = 1800;

    /** @var string Bearer API key. */
    private $apikey;
    /** @var string Endpoint URL. */
    private $endpoint;

    /**
     * The voices OpenAI's TTS models offer, keyed by voice id.
     *
     * Single source of truth for the "Voices offered to learners" admin
     * checklist and the player's voice-picker labels. Mirrors the voice list
     * in OpenAI's text-to-speech documentation for `gpt-4o-mini-tts` — the
     * older `tts-1` / `tts-1-hd` models only accept the classic six (alloy,
     * echo, fable, nova, onyx, shimmer) and reject the rest.
     *
     * OpenAI exposes no API to enumerate voices, so this list is maintained
     * by hand — last synced with the OpenAI docs in July 2026. When OpenAI
     * ships a voice before this list catches up, admins can enable it
     * immediately via the "Additional voice ids" setting.
     *
     * @return array<string,string> Voice id => display label.
     */
    public static function supported_voices(): array {
        return [
            'alloy'   => 'Alloy',
            'ash'     => 'Ash',
            'ballad'  => 'Ballad',
            'cedar'   => 'Cedar',
            'coral'   => 'Coral',
            'echo'    => 'Echo',
            'fable'   => 'Fable',
            'marin'   => 'Marin',
            'nova'    => 'Nova',
            'onyx'    => 'Onyx',
            'sage'    => 'Sage',
            'shimmer' => 'Shimmer',
            'verse'   => 'Verse',
        ];
    }

    /**
     * Human-readable label for a voice id, falling back to a capitalised
     * form of the raw id for voices enabled via the extra-ids setting.
     *
     * @param string $voice Voice id, e.g. 'marin'.
     * @return string e.g. 'Marin'.
     */
    public static function voice_display_name(string $voice): string {
        $key = strtolower(trim($voice));
        return self::supported_voices()[$key] ?? \core_text::strtotitle($key);
    }

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
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if ($this->apikey === '') {
            throw new \moodle_exception('error_no_apikey', 'local_aireader');
        }
        http_guard::assert_safe_url($this->endpoint);

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
            'CURLOPT_PROTOCOLS'      => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
        ]);

        $response = $curl->post($this->endpoint, $payload);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception(
                'error_tts_http',
                'local_aireader',
                '',
                http_guard::sanitize_error($status, $response)
            );
        }

        if (!is_string($response) || $response === '') {
            throw new \moodle_exception('error_tts_empty', 'local_aireader');
        }
        return $response;
    }

    /**
     * Split input into chunks at sentence boundaries, staying under both the
     * character and the token ceiling.
     *
     * Sentence detection handles Western terminators (. ? !) and CJK ones
     * (。．！？), the latter often having no trailing whitespace — without this
     * an unspaced Japanese/Chinese narration is treated as one giant "sentence"
     * and only the hard cut keeps it in bounds.
     *
     * @param string $text Input text.
     * @param int $maxchars Maximum chars per chunk (<=0 uses {@see DEFAULT_CHUNK_SIZE}).
     * @param int $maxtokens Maximum estimated tokens per chunk (<=0 uses {@see DEFAULT_MAX_TOKENS}).
     * @return string[]
     */
    public static function chunk_text(string $text, int $maxchars, int $maxtokens = self::DEFAULT_MAX_TOKENS): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if ($maxchars <= 0) {
            $maxchars = self::DEFAULT_CHUNK_SIZE;
        }
        if ($maxtokens <= 0) {
            $maxtokens = self::DEFAULT_MAX_TOKENS;
        }

        $fits = static function (string $s) use ($maxchars, $maxtokens): bool {
            return mb_strlen($s) <= $maxchars && self::estimate_tokens($s) <= $maxtokens;
        };
        if ($fits($text)) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[\.\?\!。．！？])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $chunks = [];
        $buffer = '';
        foreach ($sentences as $sentence) {
            $candidate = $buffer === '' ? $sentence : ($buffer . ' ' . $sentence);
            if (!$fits($candidate) && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $sentence;
            } else {
                $buffer = $candidate;
            }
            // Hard-split a buffer that on its own exceeds a ceiling: a very long
            // sentence, or an unbroken CJK run with no sentence terminators.
            while (!$fits($buffer)) {
                $cut = self::hard_cut_index($buffer, $maxchars, $maxtokens);
                $chunks[] = trim(mb_substr($buffer, 0, $cut));
                $buffer = trim(mb_substr($buffer, $cut));
                if ($buffer === '') {
                    break;
                }
            }
        }
        if (trim($buffer) !== '') {
            $chunks[] = $buffer;
        }
        return array_values(array_filter($chunks, static function ($c) {
            return trim($c) !== '';
        }));
    }

    /**
     * Estimate the OpenAI token count of a string without a tokenizer dependency.
     *
     * "Wide" scripts (CJK ideographs, kana, Hangul, fullwidth forms) tokenize at
     * roughly one token per character; other text averages ~4 characters per
     * token. Rounded up, and biased to over- rather than under-count so the
     * result is a safe ceiling for chunking.
     *
     * @param string $text
     * @return int
     */
    public static function estimate_tokens(string $text): int {
        if ($text === '') {
            return 0;
        }
        $pattern = '/['
            . '\x{3000}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}'
            . '\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}\x{FF00}-\x{FFEF}'
            . ']/u';
        $wide = preg_match_all($pattern, $text) ?: 0;
        $rest = max(0, mb_strlen($text) - $wide);
        return (int)ceil($wide + $rest / 4);
    }

    /**
     * Find the largest prefix length (in characters) of $s that fits both ceilings,
     * preferring to break on whitespace.
     *
     * @param string $s
     * @param int $maxchars
     * @param int $maxtokens
     * @return int Character offset to cut at (always >= 1).
     */
    private static function hard_cut_index(string $s, int $maxchars, int $maxtokens): int {
        $limit = min(mb_strlen($s), $maxchars);
        // Shrink until the token estimate of the prefix fits, then refine.
        while ($limit > 1 && self::estimate_tokens(mb_substr($s, 0, $limit)) > $maxtokens) {
            $limit = (int)floor($limit * 0.9);
        }
        $space = mb_strrpos(mb_substr($s, 0, $limit), ' ');
        if ($space !== false && $space > 0) {
            return $space;
        }
        return max(1, $limit);
    }
}
