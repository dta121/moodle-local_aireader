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
 * Raised when the TTS endpoint rejects a chunk for exceeding its input limit.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\exception;

/**
 * A recoverable TTS rejection: the input was too long, so split and retry.
 *
 * Distinct from a generic HTTP 400 on purpose. A malformed request also returns
 * 400, and re-splitting that would just burn calls against the same error, so
 * only the input-limit rejection gets its own type and its own retry path.
 *
 * @package local_aireader
 */
class tts_input_too_long extends api_http_error {
    /**
     * Whether an API error response is the "input too long" rejection.
     *
     * Matched on the message because the endpoint returns the same 400 status
     * for unrelated problems, and the token counts in the text are the only
     * thing distinguishing them.
     *
     * @param int $status HTTP status returned by the endpoint.
     * @param string $message Sanitised error message from the response body.
     * @return bool True when this is the input-limit rejection.
     */
    public static function matches(int $status, string $message): bool {
        if ($status !== 400) {
            return false;
        }
        return (bool)preg_match('/maximum input limit|over the maximum input|too long/i', $message);
    }

    /**
     * Constructor.
     *
     * @param string $detail Sanitised error message from the endpoint.
     */
    public function __construct(string $detail = '') {
        parent::__construct('error_tts_input_too_long', 400, $detail);
    }
}
