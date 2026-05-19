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
 * HTTP outbound-call guard for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Defense-in-depth guard for outbound HTTP calls to OpenAI-compatible endpoints.
 *
 * Two responsibilities:
 *
 *  1. Reject outbound URLs that point at private/link-local/loopback hosts or
 *     non-HTTPS schemes. Endpoint URLs are admin-configurable, so a compromised
 *     admin account could otherwise pivot to SSRF or redirect the Bearer key to
 *     an attacker host. The runtime check enforces the policy regardless of
 *     whatever was saved in admin settings.
 *
 *  2. Sanitize OpenAI error response bodies before they're stored on the asset
 *     row or written to cron logs. The goal is to keep enough signal for
 *     debugging (HTTP status, a short error class) without persisting Bearer
 *     tokens or other reflected secrets.
 *
 * @package local_aireader
 */
class http_guard {
    /** @var int Max length of the sanitized error snippet kept in storage/logs. */
    private const ERROR_SNIPPET_MAX = 200;

    /**
     * Assert that the URL is safe to call from a backend cron context.
     *
     * @param string $url Outbound URL.
     * @throws \moodle_exception When the URL is not HTTPS or resolves to a
     *                           private/loopback/link-local address.
     */
    public static function assert_safe_url(string $url): void {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \moodle_exception('error_endpoint_invalid', 'local_aireader');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new \moodle_exception('error_endpoint_invalid', 'local_aireader');
        }
        $host = strtolower($parts['host']);
        if (self::is_blocked_host($host)) {
            throw new \moodle_exception('error_endpoint_invalid', 'local_aireader');
        }
    }

    /**
     * Whether a hostname is on the blocklist (loopback / private / link-local /
     * cloud metadata service).
     *
     * @param string $host Lowercased hostname.
     * @return bool
     */
    private static function is_blocked_host(string $host): bool {
        $blockednames = [
            'localhost',
            'localhost.localdomain',
            'ip6-localhost',
            'ip6-loopback',
        ];
        if (in_array($host, $blockednames, true)) {
            return true;
        }
        // Numeric IP: range-check directly. For hostnames we deliberately do
        // not resolve DNS at validation time — adding a DNS lookup here would
        // introduce TOCTOU and a DoS vector. The intent is to catch obvious
        // mistakes (someone pasting an internal URL into admin settings); a
        // determined attacker who controls DNS is out of scope.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_private_ip($host);
        }
        return false;
    }

    /**
     * Whether an IP literal sits in a range that should never receive plugin
     * outbound traffic.
     *
     * @param string $ip
     * @return bool
     */
    private static function is_private_ip(string $ip): bool {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }
        // Explicitly block AWS / GCP metadata service.
        if ($ip === '169.254.169.254' || $ip === 'fd00:ec2::254') {
            return true;
        }
        return false;
    }

    /**
     * Produce a short, secret-free description of an HTTP failure suitable for
     * persisting to `local_aireader_asset.lasterror` and emitting to cron logs.
     *
     * Strips Bearer tokens, long opaque alphanumerics, and JSON noise; keeps
     * the HTTP status and a short message.
     *
     * @param int $status HTTP status code.
     * @param mixed $body Raw response body (any type returned by curl).
     * @return string Short, single-line description.
     */
    public static function sanitize_error(int $status, $body): string {
        $snippet = '';
        if (is_string($body) && $body !== '') {
            $decoded = @json_decode($body, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $snippet = (string)$decoded['error']['message'];
            } else {
                $snippet = $body;
            }
        }
        // Hard limit before regex work so we never burn cycles on adversarial input.
        $snippet = substr($snippet, 0, 1000);
        // Strip Bearer tokens and long opaque secrets.
        $snippet = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $snippet);
        $snippet = preg_replace('/sk-[A-Za-z0-9._\-]{10,}/', 'sk-[redacted]', $snippet);
        $snippet = preg_replace('/[A-Za-z0-9_\-]{40,}/', '[redacted]', $snippet);
        $snippet = preg_replace('/\s+/', ' ', $snippet);
        $snippet = trim($snippet);
        if (mb_strlen($snippet) > self::ERROR_SNIPPET_MAX) {
            $snippet = mb_substr($snippet, 0, self::ERROR_SNIPPET_MAX) . '…';
        }
        return $snippet === '' ? "HTTP {$status}" : "HTTP {$status}: {$snippet}";
    }
}
