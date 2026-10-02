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
 * Storage facade for local_aireader generated audio files.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Storage facade for Moodle files and private S3 audio objects.
 *
 * Generation initially uses the Moodle File API, including any site-wide
 * alternative file system. S3 primary storage may subsequently release that
 * local file once a verified remote copy exists. Both locations use the same
 * access-controlled pluginfile URL; callers never receive a public bucket URL.
 *
 * @package local_aireader
 */
class storage {
    /** Plugin component identifier used for the file area. */
    public const COMPONENT = 'local_aireader';
    /** File area name where generated mp3s live. */
    public const FILEAREA = 'audio';

    /**
     * Store an mp3 against an asset row, replacing any prior file for the same asset.
     *
     * @param int $assetid local_aireader_asset.id
     * @param int $contextid Module context id to own the file.
     * @param string $mp3bytes Raw mp3 binary content.
     * @return \stored_file The newly created file.
     */
    public static function store_mp3(int $assetid, int $contextid, string $mp3bytes): \stored_file {
        $fs = get_file_storage();

        $fs->delete_area_files($contextid, self::COMPONENT, self::FILEAREA, $assetid);

        $record = [
            'contextid' => $contextid,
            'component' => self::COMPONENT,
            'filearea'  => self::FILEAREA,
            'itemid'    => $assetid,
            'filepath'  => '/',
            'filename'  => "asset-{$assetid}.mp3",
            'mimetype'  => 'audio/mpeg',
        ];
        return $fs->create_file_from_string($record, $mp3bytes);
    }

    /**
     * Find the asset's file in Moodle storage, without fetching remote content.
     *
     * @param \stdClass $asset Asset row.
     * @return \stored_file|null Local file, or null after S3 offloading.
     */
    public static function get_local_file(\stdClass $asset): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            (int)$asset->contextid,
            self::COMPONENT,
            self::FILEAREA,
            (int)$asset->id,
            'itemid',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Whether local or completed remote audio exists in the storage catalog.
     *
     * This is a metadata-only lookup and does not contact S3.
     *
     * @param \stdClass $asset Asset row.
     * @return bool
     */
    public static function has_audio(\stdClass $asset): bool {
        return self::get_local_file($asset) !== null || s3_storage::get_remote($asset) !== null;
    }

    /**
     * Read audio for alignment, fetching S3-only files into request temp space.
     *
     * @param \stdClass $asset Asset row.
     * @return string|null Audio bytes, or null when neither location is catalogued.
     */
    public static function get_audio_content(\stdClass $asset): ?string {
        $file = self::get_local_file($asset);
        if ($file) {
            $content = $file->get_content();
            if ($content === false) {
                throw new \file_exception('storedfilecannotread');
            }
            return $content;
        }
        if (!s3_storage::get_remote($asset)) {
            return null;
        }

        $path = make_request_directory() . '/audio.mp3';
        try {
            self::copy_audio_to_path($asset, $path);
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \file_exception('storedfilecannotread');
            }
            return $content;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Copy an asset into a temporary file for ZIP packaging or processing.
     *
     * Downloaded bytes must match the uploaded content hash and size before a
     * caller consumes them. Destinations come from the saved object record,
     * so changing the current storage mode or bucket does not strand old audio.
     *
     * @param \stdClass $asset Asset row.
     * @param string $path Destination path in a caller-owned temporary directory.
     * @return void
     */
    public static function copy_audio_to_path(\stdClass $asset, string $path): void {
        $file = self::get_local_file($asset);
        if ($file) {
            if (!$file->copy_content_to($path)) {
                throw new \file_exception('storedfilecannotread');
            }
            return;
        }

        $remote = s3_storage::get_remote($asset);
        if (!$remote) {
            throw new \file_exception('storedfilecannotread');
        }
        try {
            s3_storage::client((string)$remote->region)->download_file(
                (string)$remote->bucket,
                (string)$remote->objectkey,
                $path
            );
            clearstatcache(true, $path);
            if (
                !is_file($path)
                || filesize($path) !== (int)$remote->filesize
                || !hash_equals((string)$remote->contenthash, (string)sha1_file($path))
            ) {
                throw new \moodle_exception('error_s3_integrity', 'local_aireader');
            }
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    /**
     * Return the pluginfile URL for the stored audio for an asset, or null if absent.
     *
     * @param \stdClass $asset local_aireader_asset row.
     * @return \moodle_url|null
     */
    public static function get_audio_url(\stdClass $asset): ?\moodle_url {
        return self::build_pluginfile_url($asset, false);
    }

    /**
     * Return the force-download pluginfile URL for an asset's audio, or null if
     * absent. Used by the player's "Download" control so the browser saves the
     * mp3 rather than streaming it inline.
     *
     * @param \stdClass $asset local_aireader_asset row.
     * @return \moodle_url|null
     */
    public static function get_download_url(\stdClass $asset): ?\moodle_url {
        return self::build_pluginfile_url($asset, true);
    }

    /**
     * Build the pluginfile URL for an asset's stored audio.
     *
     * @param \stdClass $asset local_aireader_asset row.
     * @param bool $forcedownload Whether the URL should force a download.
     * @return \moodle_url|null Null when no file is stored for the asset.
     */
    private static function build_pluginfile_url(\stdClass $asset, bool $forcedownload): ?\moodle_url {
        $file = self::get_local_file($asset);
        if (!$file && !s3_storage::get_remote($asset)) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            (int)$asset->contextid,
            self::COMPONENT,
            self::FILEAREA,
            (int)$asset->id,
            '/',
            $file ? $file->get_filename() : 'asset-' . (int)$asset->id . '.mp3',
            $forcedownload
        );
    }
}
