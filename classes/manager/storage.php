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
 * Thin storage facade around the Moodle File API.
 *
 * The plugin deliberately stores generated mp3s through {@see get_file_storage()}
 * so that any alternative file system configured at the site level (including
 * S3-backed storage) is used transparently. Do not add direct cloud SDK calls
 * here without a strong reason: doing so bypasses Moodle's access control and
 * file lifecycle hooks.
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
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $asset->contextid,
            self::COMPONENT,
            self::FILEAREA,
            $asset->id,
            'itemid',
            false
        );
        if (!$files) {
            return null;
        }
        $file = reset($files);
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            $forcedownload
        );
    }
}
