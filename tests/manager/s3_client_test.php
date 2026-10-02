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
 * Offline tests for the Amazon S3 transport.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for streaming, checksums, private objects and sanitized failures.
 *
 * @coversDefaultClass \local_aireader\manager\s3_client
 */
final class s3_client_test extends \advanced_testcase {
    /**
     * Uploaded audio streams remain valid during transfer and close afterwards.
     *
     * @covers ::put_file
     */
    public function test_upload_streams_audio_without_overriding_bucket_security(): void {
        $bytes = "ID3\0audio-content";
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $file = $this->createMock(\stored_file::class);
        $file->expects($this->once())->method('get_content_file_handle')->willReturn($stream);
        $file->expects($this->once())->method('get_filesize')->willReturn(strlen($bytes));
        $file->expects($this->once())->method('get_contenthash')->willReturn(sha1($bytes));
        $sdk = $this->sdk(function (string $method, array $arguments) use ($stream, $bytes): void {
            $this->assertSame('putObject', $method);
            $params = $arguments[0];
            $this->assertSame('private-audio', $params['Bucket']);
            $this->assertSame('site/audio/12.mp3', $params['Key']);
            $this->assertSame($stream, $params['Body']);
            $this->assertTrue(is_resource($params['Body']));
            $this->assertSame($bytes, stream_get_contents($params['Body']));
            $this->assertSame(strlen($bytes), $params['ContentLength']);
            $this->assertSame('audio/mpeg', $params['ContentType']);
            $this->assertSame('private, no-store', $params['CacheControl']);
            $this->assertSame('SHA1', $params['ChecksumAlgorithm']);
            $this->assertSame(base64_encode(sha1($bytes, true)), $params['ChecksumSHA1']);
            $this->assertArrayNotHasKey('ACL', $params);
            $this->assertArrayNotHasKey('ServerSideEncryption', $params);
            $this->assertArrayNotHasKey('SSEKMSKeyId', $params);
        });

        (new s3_client('us-east-1', $sdk))->put_file('private-audio', 'site/audio/12.mp3', $file);

        $this->assertFalse(is_resource($stream));
    }

    /**
     * Moodle's bundled SDK validates and serializes our checksum upload offline.
     *
     * A real SDK handler stack catches unsupported service parameters that a plain
     * PHP test double cannot detect. The terminal handler never sends HTTP traffic.
     *
     * @covers ::put_file
     */
    public function test_upload_serializes_with_the_bundled_aws_sdk(): void {
        $bytes = "ID3\0SDK integration audio";
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $file = $this->createMock(\stored_file::class);
        $file->method('get_content_file_handle')->willReturn($stream);
        $file->method('get_filesize')->willReturn(strlen($bytes));
        $file->method('get_contenthash')->willReturn(sha1($bytes));
        $handler = new \Aws\MockHandler();
        $handler->append(function (\Aws\CommandInterface $command, \Psr\Http\Message\RequestInterface $request) use ($bytes) {
            $this->assertSame('PutObject', $command->getName());
            $this->assertSame('PUT', $request->getMethod());
            $this->assertSame('audio/mpeg', $request->getHeaderLine('Content-Type'));
            $this->assertSame('SHA1', $request->getHeaderLine('x-amz-sdk-checksum-algorithm'));
            $this->assertSame(base64_encode(sha1($bytes, true)), $request->getHeaderLine('x-amz-checksum-sha1'));
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertFalse($request->hasHeader('x-amz-server-side-encryption'));
            $this->assertSame($bytes, (string)$request->getBody());
            return new \Aws\Result(['ChecksumSHA1' => base64_encode(sha1($bytes, true))]);
        });
        $sdk = new \Aws\S3\S3Client([
            'version' => '2006-03-01',
            'region' => 'us-east-1',
            'signature_version' => 'v4',
            'credentials' => new \Aws\Credentials\Credentials('offline-test-key', 'offline-test-secret'),
            'handler' => $handler,
            'retries' => 0,
        ]);

        (new s3_client('us-east-1', $sdk))->put_file('private-audio', 'site/audio/12.mp3', $file);

        $this->assertCount(0, $handler);
        $this->assertFalse(is_resource($stream));
    }

    /**
     * Failed uploads close the source and preserve only safe AWS diagnostics.
     *
     * @covers ::put_file
     * @covers ::safe_error_details
     */
    public function test_upload_failure_closes_stream_and_removes_secrets(): void {
        $stream = fopen('php://temp', 'w+b');
        $file = $this->createMock(\stored_file::class);
        $file->method('get_content_file_handle')->willReturn($stream);
        $file->method('get_filesize')->willReturn(0);
        $file->method('get_contenthash')->willReturn(sha1(''));
        $sdk = $this->sdk(function (): void {
            throw $this->sdk_error('AccessDenied', 403);
        });

        try {
            (new s3_client('us-east-1', $sdk))->put_file('private-audio', 'audio.mp3', $file);
            $this->fail('The upload must report a failure.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3transfererror', $error->errorcode);
            $this->assertSame('PutObject: AccessDenied (HTTP 403)', $error->a);
            $this->assert_sanitized($error);
        }

        $this->assertFalse(is_resource($stream));
    }

    /**
     * An unreadable local source must never trigger an AWS operation.
     *
     * @covers ::put_file
     */
    public function test_unreadable_source_fails_before_upload(): void {
        $file = $this->createMock(\stored_file::class);
        $file->method('get_content_file_handle')->willThrowException(new \RuntimeException('secret-local-path'));
        $sdk = $this->sdk(function (): void {
            $this->fail('An unreadable source must not be uploaded.');
        });

        try {
            (new s3_client('us-east-1', $sdk))->put_file('private-audio', 'audio.mp3', $file);
            $this->fail('The unreadable source must report a failure.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3filereaderror', $error->errorcode);
            $this->assert_sanitized($error);
        }
    }

    /**
     * ZIP audio retrieval uses the SDK disk sink without materializing a body.
     *
     * @covers ::download_file
     */
    public function test_download_streams_to_caller_path(): void {
        $path = make_request_directory() . '/audio.mp3';
        $sdk = $this->sdk(function (string $method, array $arguments) use ($path): void {
            $this->assertSame('getObject', $method);
            $this->assertSame([
                'Bucket' => 'private-audio',
                'Key' => 'site/audio/12.mp3',
                'SaveAs' => $path,
            ], $arguments[0]);
            file_put_contents($arguments[0]['SaveAs'], 'ID3 downloaded audio');
        });

        (new s3_client('us-east-1', $sdk))->download_file('private-audio', 'site/audio/12.mp3', $path);

        $this->assertSame('ID3 downloaded audio', file_get_contents($path));
    }

    /**
     * Remote deletion targets one object without changing bucket permissions.
     *
     * @covers ::delete_file
     */
    public function test_delete_targets_only_the_requested_object(): void {
        $sdk = $this->sdk(function (string $method, array $arguments): void {
            $this->assertSame('deleteObject', $method);
            $this->assertSame([['Bucket' => 'private-audio', 'Key' => 'audio.mp3']], $arguments);
        });

        (new s3_client('us-east-1', $sdk))->delete_file('private-audio', 'audio.mp3');
    }

    /**
     * Read and delete failures never expose signed URLs or raw errors.
     *
     * @covers ::download_file
     * @covers ::delete_file
     * @covers ::safe_error_details
     */
    public function test_failures_reject_unsafe_error_codes_and_statuses(): void {
        $sdk = $this->sdk(function (): void {
            throw $this->sdk_error('https://secret-token.invalid/', 999);
        });
        $client = new s3_client('us-east-1', $sdk);
        foreach (['download_file', 'delete_file'] as $method) {
            try {
                if ($method === 'delete_file') {
                    $client->delete_file('private-audio', 'audio.mp3');
                } else {
                    $client->{$method}('private-audio', 'audio.mp3', 'audio.mp3');
                }
                $this->fail('The failed operation must report a failure.');
            } catch (\moodle_exception $error) {
                $this->assertSame('s3transfererror', $error->errorcode);
                $this->assertSame($method === 'delete_file' ? 'DeleteObject' : 'GetObject', $error->a);
                $this->assert_sanitized($error);
            }
        }
    }

    /**
     * Region input must remain a region name, never an endpoint or path.
     *
     * @covers ::__construct
     */
    public function test_invalid_region_is_rejected(): void {
        try {
            new s3_client('us-east-1.example.invalid/', new \stdClass());
            $this->fail('The invalid region must be rejected.');
        } catch (\moodle_exception $error) {
            $this->assertSame('s3clienterror', $error->errorcode);
            $this->assertNull($error->getPrevious());
        }
    }

    /**
     * Assert the original exception cannot leak through Moodle debug output.
     *
     * @param \moodle_exception $error Sanitized exception.
     */
    private function assert_sanitized(\moodle_exception $error): void {
        $this->assertStringNotContainsString('secret-', $error->getMessage());
        $this->assertNull($error->debuginfo);
        $this->assertNull($error->getPrevious());
    }

    /**
     * Construct an SDK stand-in without requiring the optional AWS dependency.
     *
     * @param callable $callback Callback receiving the SDK method and arguments.
     * @return object SDK stand-in.
     */
    private function sdk(callable $callback): object {
        return new class ($callback) {
            /** @var \Closure SDK operation handler. */
            private \Closure $callback;

            /**
             * Save the SDK operation handler.
             *
             * @param callable $callback SDK operation handler.
             */
            public function __construct(callable $callback) {
                $this->callback = \Closure::fromCallable($callback);
            }

            /**
             * Dispatch an SDK operation to the test callback.
             *
             * @param string $method SDK operation name.
             * @param array $arguments SDK operation arguments.
             * @return mixed Callback result.
             */
            public function __call(string $method, array $arguments) {
                return ($this->callback)($method, $arguments);
            }
        };
    }

    /**
     * Simulate an AWS service error whose raw text contains private request data.
     *
     * @param string $code Service error code.
     * @param int $status HTTP status.
     * @return \RuntimeException Fake SDK error.
     */
    private function sdk_error(string $code, int $status): \RuntimeException {
        return new class ($code, $status) extends \RuntimeException {
            /** @var string Service error code. */
            private string $awscode;
            /** @var int HTTP status. */
            private int $status;

            /**
             * Set simulated private request details and safe service fields.
             *
             * @param string $code Service error code.
             * @param int $status HTTP status.
             */
            public function __construct(string $code, int $status) {
                parent::__construct('secret-token at https://secret-credentials.invalid/signed?secret-signature=key');
                $this->awscode = $code;
                $this->status = $status;
            }

            /**
             * Return the simulated service error code.
             *
             * @return string Service error code.
             */
            public function getAwsErrorCode(): string { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
                return $this->awscode;
            }

            /**
             * Return the simulated response status.
             *
             * @return int HTTP status.
             */
            public function getStatusCode(): int { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
                return $this->status;
            }
        };
    }
}
