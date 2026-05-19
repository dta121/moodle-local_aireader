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
 * OpenAI Whisper client for sentence-level audio alignment.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

use local_aireader\manager\http_guard;

/**
 * Thin client over POST /v1/audio/transcriptions with verbose_json + segment
 * timestamps.
 *
 * Returns a normalised array of segments shaped for {@see segment_manager::store_for_asset()}.
 *
 * @package local_aireader
 */
class openai_aligner {
    /** @var string */
    private $apikey;
    /** @var string */
    private $endpoint;
    /** @var string */
    private $model;

    /**
     * Construct an aligner, optionally overriding configured values.
     *
     * @param string|null $apikey
     * @param string|null $endpoint
     * @param string|null $model
     */
    public function __construct(?string $apikey = null, ?string $endpoint = null, ?string $model = null) {
        $this->apikey = $apikey ?? (string)get_config('local_aireader', 'openai_api_key');
        $this->endpoint = $endpoint ?? (string)(get_config('local_aireader', 'alignment_endpoint')
            ?: 'https://api.openai.com/v1/audio/transcriptions');
        $this->model = $model ?? (string)(get_config('local_aireader', 'alignment_model')
            ?: 'whisper-1');
    }

    /**
     * Align an audio file by submitting it to Whisper. Returns a normalised
     * list of {startms, endms, segtext} entries ready for segment_manager.
     *
     * @param string $audiobytes Raw bytes of the audio file (mp3).
     * @param string $filename Name to give the upload (used for content sniffing).
     * @param string $langhint Optional ISO 639-1 lang hint to Whisper (e.g. 'en').
     * @return array
     * @throws \moodle_exception on transport, API, or empty-result errors.
     */
    public function align(string $audiobytes, string $filename, string $langhint = ''): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->apikey === '') {
            throw new \moodle_exception('error_no_apikey', 'local_aireader');
        }
        if ($audiobytes === '') {
            throw new \moodle_exception('error_alignment_empty_input', 'local_aireader');
        }
        http_guard::assert_safe_url($this->endpoint);

        // Stage the audio in a tmp file so curl can attach it as multipart/form-data.
        $tmppath = make_request_directory() . '/' . $filename;
        file_put_contents($tmppath, $audiobytes);

        $curlfile = new \CURLFile($tmppath, 'audio/mpeg', $filename);

        $postdata = [
            'file'                       => $curlfile,
            'model'                      => $this->model,
            'response_format'             => 'verbose_json',
            'timestamp_granularities[]'  => 'segment',
        ];
        if ($langhint !== '') {
            // Whisper expects an ISO 639-1 code; strip Moodle's locale variants ("zh_cn" -> "zh").
            $postdata['language'] = strtolower(preg_replace('/[_-].*$/', '', $langhint));
        }

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Accept: application/json',
        ]);
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 180,
            'CURLOPT_CONNECTTIMEOUT' => 20,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_PROTOCOLS'      => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
        ]);

        $response = $curl->post($this->endpoint, $postdata);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception(
                'error_alignment_http',
                'local_aireader',
                '',
                http_guard::sanitize_error($status, $response)
            );
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded) || empty($decoded['segments']) || !is_array($decoded['segments'])) {
            throw new \moodle_exception('error_alignment_empty_response', 'local_aireader');
        }

        $out = [];
        foreach ($decoded['segments'] as $seg) {
            $text = isset($seg['text']) ? trim((string)$seg['text']) : '';
            if ($text === '') {
                continue;
            }
            $out[] = [
                'startms' => (int)round(((float)($seg['start'] ?? 0)) * 1000),
                'endms'   => (int)round(((float)($seg['end'] ?? 0)) * 1000),
                'segtext' => $text,
            ];
        }
        return $out;
    }
}
