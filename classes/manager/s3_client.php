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
 * Private Amazon S3 transport for generated audio.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Small SDK adapter using the server's default AWS credential provider chain.
 *
 * Objects inherit the bucket's encryption settings. No ACL is sent, so buckets
 * with S3 Object Ownership's bucket-owner-enforced setting are supported.
 * Moodle access checks remain in the pluginfile endpoint.
 */
class s3_client {
    /** @var object AWS S3 client, or an injected test double. */
    private object $client;

    /**
     * Construct the transport without storing AWS credentials in Moodle.
     *
     * Moodle versions which register the SDK autoloader use their bundled SDK.
     * Other versions can install the plugin's optional Composer dependency.
     *
     * @param string $region AWS region containing the bucket.
     * @param object|null $client SDK test double; omitted in production.
     */
    public function __construct(string $region, ?object $client = null) {
        if (!preg_match('/\A[a-z]{2}(?:-[a-z0-9]+)+-\d+\z/', $region)) {
            throw new \moodle_exception('s3clienterror', 'local_aireader');
        }
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        if (!class_exists(\Aws\S3\S3Client::class)) {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (is_readable($autoload)) {
                require_once($autoload);
            }
        }
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \moodle_exception('s3sdkmissing', 'local_aireader');
        }

        try {
            $this->client = new \Aws\S3\S3Client([
                'version' => '2006-03-01',
                'region' => $region,
                'signature_version' => 'v4',
                'retries' => 2,
                'http' => [
                    'connect_timeout' => 5,
                    'timeout' => 120,
                ],
            ]);
        } catch (\Throwable $error) {
            // Never retain SDK exceptions: their messages may contain credentials or signed URLs.
            throw new \moodle_exception('s3clienterror', 'local_aireader');
        }
    }

    /**
     * Stream an existing Moodle file into a private S3 object.
     *
     * @param string $bucket Destination bucket.
     * @param string $key Destination object key.
     * @param \stored_file $file Source audio file.
     */
    public function put_file(string $bucket, string $key, \stored_file $file): void {
        try {
            $handle = $file->get_content_file_handle();
        } catch (\Throwable $error) {
            throw new \moodle_exception('s3filereaderror', 'local_aireader');
        }
        if (!is_resource($handle)) {
            throw new \moodle_exception('s3filereaderror', 'local_aireader');
        }

        try {
            $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $handle,
                'ContentLength' => (int)$file->get_filesize(),
                'ContentType' => 'audio/mpeg',
                'CacheControl' => 'private, no-store',
                'ChecksumAlgorithm' => 'SHA1',
                'ChecksumSHA1' => base64_encode(hex2bin($file->get_contenthash())),
            ]);
        } catch (\Throwable $error) {
            throw new \moodle_exception('s3transfererror', 'local_aireader', '', self::safe_error_details('PutObject', $error));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Write a small in-memory object, used by the settings connection check.
     *
     * @param string $bucket Destination bucket.
     * @param string $key Destination object key.
     * @param string $content Object body.
     */
    public function put_content(string $bucket, string $key, string $content): void {
        try {
            $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $content,
                'ContentType' => 'text/plain',
                'CacheControl' => 'private, no-store',
                'ChecksumAlgorithm' => 'SHA1',
                'ChecksumSHA1' => base64_encode(sha1($content, true)),
            ]);
        } catch (\Throwable $error) {
            throw new \moodle_exception('s3transfererror', 'local_aireader', '', self::safe_error_details('PutObject', $error));
        }
    }

    /**
     * Download audio without loading the object into PHP memory.
     *
     * The caller owns the destination's lifecycle, including removal of partial
     * downloads on failure. Use a temporary path when constructing ZIP downloads.
     *
     * @param string $bucket Source bucket.
     * @param string $key Source object key.
     * @param string $path Destination pathname.
     */
    public function download_file(string $bucket, string $key, string $path): void {
        try {
            $this->client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SaveAs' => $path,
            ]);
        } catch (\Throwable $error) {
            throw new \moodle_exception('s3transfererror', 'local_aireader', '', self::safe_error_details('GetObject', $error));
        }
    }

    /**
     * Delete an audio object. S3 treats an absent object as already deleted.
     *
     * @param string $bucket Source bucket.
     * @param string $key Object key.
     */
    public function delete_file(string $bucket, string $key): void {
        try {
            $this->client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable $error) {
            throw new \moodle_exception('s3transfererror', 'local_aireader', '', self::safe_error_details('DeleteObject', $error));
        }
    }

    /**
     * Return useful service diagnostics without leaking SDK request details.
     *
     * @param string $operation Fixed AWS operation name.
     * @param \Throwable $error SDK or network error.
     * @return string Sanitized service diagnostics.
     */
    private static function safe_error_details(string $operation, \Throwable $error): string {
        $details = $operation;
        if (method_exists($error, 'getAwsErrorCode')) {
            $code = $error->getAwsErrorCode();
            if (is_string($code) && preg_match('/\A[A-Za-z][A-Za-z0-9]{0,79}\z/', $code)) {
                $details .= ': ' . $code;
            }
        }
        if (method_exists($error, 'getStatusCode')) {
            $status = $error->getStatusCode();
            if (is_int($status) && $status >= 100 && $status <= 599) {
                $details .= ' (HTTP ' . $status . ')';
            }
        }
        return $details;
    }
}
