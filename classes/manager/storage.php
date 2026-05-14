<?php
namespace local_aireader\manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin storage facade around the Moodle File API.
 *
 * The plugin deliberately stores generated mp3s through {@see get_file_storage()}
 * so that any alternative file system configured at the site level (including
 * S3-backed storage) is used transparently. Do not add direct cloud SDK calls
 * here without a strong reason: doing so bypasses Moodle's access control and
 * file lifecycle hooks.
 */
class storage {

    public const COMPONENT = 'local_aireader';
    public const FILEAREA  = 'audio';

    /**
     * Store an mp3 against an asset row. Returns the stored_file.
     */
    public static function store_mp3(int $assetid, int $contextid, string $mp3bytes): \stored_file {
        $fs = get_file_storage();

        // Replace any prior file for this asset.
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
     * Return the public-via-pluginfile URL for the stored audio, or null.
     */
    public static function get_audio_url(\stdClass $asset): ?\moodle_url {
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
            false
        );
    }
}
