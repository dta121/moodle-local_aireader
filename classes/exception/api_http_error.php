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
 * An OpenAI API call that came back with a non-2xx status.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\exception;

/**
 * Carries the HTTP status so a task can tell a retry from a permanent failure.
 *
 * Previously the status was folded into a sanitised message string, so tasks
 * had no way to distinguish "the API was briefly unavailable" from "this
 * request will be rejected identically forever". Both were rethrown, so
 * deterministic failures retried daily and accumulated in the failed task
 * queue indefinitely.
 *
 * Extends moodle_exception so existing catch blocks keep working.
 *
 * @package local_aireader
 */
class api_http_error extends \moodle_exception {
    /** @var int The HTTP status returned by the endpoint. */
    public $status;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string identifier for the message.
     * @param int $status HTTP status returned by the endpoint.
     * @param string $detail Sanitised error message from the response body.
     */
    public function __construct(string $errorcode, int $status, string $detail = '') {
        $this->status = $status;
        parent::__construct($errorcode, 'local_aireader', '', $detail);
    }

    /**
     * Whether a request that failed with this status is worth sending again.
     *
     * Server errors and the explicitly transient 4xx statuses are retried.
     * Every other 4xx describes something wrong with the request itself, which
     * an identical retry cannot fix, so those are permanent by default. A
     * status of 0 means the request never completed (DNS, TLS, timeout), which
     * is transient.
     *
     * @param int $status HTTP status.
     * @return bool True when Moodle should retry the task.
     */
    public static function retryable(int $status): bool {
        if ($status === 0 || $status >= 500) {
            return true;
        }
        // 408 request timeout, 409 conflict, 425 too early, 429 rate limited.
        return in_array($status, [408, 409, 425, 429], true);
    }
}
