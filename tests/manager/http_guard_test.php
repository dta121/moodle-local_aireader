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
 * Unit tests for the outbound HTTP guard.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see http_guard}.
 *
 * @coversDefaultClass \local_aireader\manager\http_guard
 */
final class http_guard_test extends \advanced_testcase {
    /**
     * Accepts the canonical OpenAI HTTPS endpoint without raising.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_accepts_openai(): void {
        http_guard::assert_safe_url('https://api.openai.com/v1/audio/speech');
        $this->assertTrue(true);
    }

    /**
     * Plain HTTP must be rejected even on a public host.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_http(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('http://api.openai.com/v1/audio/speech');
    }

    /**
     * `localhost` resolves to a loopback address; reject by name as well as IP.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_localhost(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://localhost/foo');
    }

    /**
     * Loopback IPv4 must be rejected.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_loopback_ipv4(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://127.0.0.1/foo');
    }

    /**
     * RFC1918 private address space must be rejected.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_private_ipv4(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://10.0.0.1/foo');
    }

    /**
     * AWS/GCP/Azure instance-metadata IP must be rejected.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_metadata_service(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://169.254.169.254/latest/meta-data/iam/security-credentials/');
    }

    /**
     * Garbage input must not crash; it raises the same configured exception.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_malformed(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('not a url at all');
    }

    /**
     * IPv6 loopback written as a bracketed literal must be rejected. parse_url
     * keeps the brackets, so this only passes once they are stripped before
     * range-checking.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_ipv6_loopback_literal(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://[::1]/v1/audio/speech');
    }

    /**
     * IPv6 unique-local address (fd00::/8) as a bracketed literal is private
     * and must be rejected.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_ipv6_ula_literal(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://[fd00::1]/foo');
    }

    /**
     * A malformed / zone-tagged IPv6 literal that does not validate as an IP
     * must still be refused rather than handed to curl.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_malformed_ipv6_literal(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://[fe80::1%25eth0]/foo');
    }

    /**
     * The decimal-integer form of 127.0.0.1 (2130706433) is routable by curl
     * but not recognised by FILTER_VALIDATE_IP; it must be blocked as an
     * obfuscated loopback address.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_decimal_ip(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://2130706433/latest/meta-data/');
    }

    /**
     * The octal-dotted form of 127.0.0.1 (0177.0.0.1) — which filter_var
     * misreads as public 177.0.0.1 while curl routes it to loopback — must be
     * blocked.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_octal_ip(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://0177.0.0.1/foo');
    }

    /**
     * The hexadecimal form of 127.0.0.1 (0x7f000001) must be blocked.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_rejects_hex_ip(): void {
        $this->expectException(\moodle_exception::class);
        http_guard::assert_safe_url('https://0x7f000001/foo');
    }

    /**
     * A canonical public dotted-quad endpoint must still be accepted — the new
     * obfuscation checks must not block legitimate numeric IPv4 hosts.
     *
     * @covers ::assert_safe_url
     */
    public function test_assert_safe_url_accepts_public_ipv4(): void {
        http_guard::assert_safe_url('https://1.1.1.1/v1/audio/speech');
        $this->assertTrue(true);
    }

    /**
     * Bearer tokens reflected in an error body must be redacted before storage.
     *
     * @covers ::sanitize_error
     */
    public function test_sanitize_error_redacts_bearer_token(): void {
        $body = 'Unauthorized: Authorization header Bearer sk-abc123def456ghi789jkl012mno345pqr678stu901vwx234';
        $out = http_guard::sanitize_error(401, $body);
        $this->assertStringNotContainsString('sk-abc123def456', $out);
        $this->assertStringNotContainsString('Bearer sk-', $out);
        $this->assertStringContainsString('401', $out);
    }

    /**
     * `sk-…` API keys outside of a Bearer prefix are still scrubbed.
     *
     * @covers ::sanitize_error
     */
    public function test_sanitize_error_redacts_bare_api_key(): void {
        $body = 'Invalid key: sk-proj-AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKKLLLL';
        $out = http_guard::sanitize_error(401, $body);
        $this->assertStringNotContainsString('AAAABBBB', $out);
        $this->assertStringContainsString('redacted', $out);
    }

    /**
     * OpenAI's standard error shape (`{"error":{"message":"…"}}`) is unwrapped.
     *
     * @covers ::sanitize_error
     */
    public function test_sanitize_error_extracts_openai_message(): void {
        $body = '{"error":{"message":"Rate limit reached for requests","type":"rate_limit"}}';
        $out = http_guard::sanitize_error(429, $body);
        $this->assertStringContainsString('Rate limit', $out);
        $this->assertStringContainsString('429', $out);
    }

    /**
     * Non-JSON bodies still produce a useful string; an empty body produces
     * just the status line.
     *
     * @covers ::sanitize_error
     */
    public function test_sanitize_error_handles_plain_and_empty_bodies(): void {
        $this->assertSame('HTTP 502: upstream timeout', http_guard::sanitize_error(502, 'upstream timeout'));
        $this->assertSame('HTTP 500', http_guard::sanitize_error(500, ''));
        $this->assertSame('HTTP 500', http_guard::sanitize_error(500, null));
    }

    /**
     * Pathological large bodies are truncated; the result stays well below
     * the storage-friendly cap.
     *
     * @covers ::sanitize_error
     */
    public function test_sanitize_error_truncates_long_body(): void {
        $body = str_repeat('a', 5000);
        $out = http_guard::sanitize_error(500, $body);
        $this->assertLessThan(300, strlen($out));
        $this->assertStringContainsString('500', $out);
    }
}
