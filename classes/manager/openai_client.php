<?php
namespace local_aireader\manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal OpenAI Audio (speech) client.
 *
 * Endpoint: POST {endpoint} with JSON body {model, voice, input, instructions, format}.
 * Response: raw audio bytes (mp3).
 */
class openai_client {

    /** @var string */
    private $apikey;
    /** @var string */
    private $endpoint;

    public function __construct(?string $apikey = null, ?string $endpoint = null) {
        $this->apikey   = $apikey ?? (string)get_config('local_aireader', 'openai_api_key');
        $this->endpoint = $endpoint ?? (string)(get_config('local_aireader', 'openai_endpoint')
            ?: 'https://api.openai.com/v1/audio/speech');
    }

    /**
     * Synthesize a single chunk and return raw mp3 bytes.
     *
     * @throws \moodle_exception on transport or API error.
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
            // The body is plaintext JSON in error cases.
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
            // Single sentence that exceeds the budget: hard-split on whitespace.
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
