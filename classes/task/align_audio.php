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
 * Ad hoc task that aligns a ready audio asset with Whisper.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use core\task\adhoc_task;
use local_aireader\exception\api_http_error;
use local_aireader\manager\asset_manager;
use local_aireader\manager\mp3_splitter;
use local_aireader\manager\openai_aligner;
use local_aireader\manager\segment_manager;
use local_aireader\manager\segment_stitcher;

/**
 * Ad hoc task: pull the mp3 for an asset, send it to Whisper, store segments.
 *
 * Queued from `generate_audio::execute()` immediately after a successful TTS run
 * when `enable_alignment` is on. Decoupled from TTS so audio plays as soon as
 * it's stored — karaoke shows up moments later when alignment finishes.
 *
 * @package local_aireader
 */
class align_audio extends adhoc_task {
    /**
     * @var int Upload ceiling the alignment endpoint enforces (25 MiB).
     *
     * Above this, Whisper aborts the read and returns HTTP 413, which retried
     * identically forever.
     */
    public const MAX_UPLOAD_BYTES = 26214400;

    /**
     * @var int Bytes to target per part when splitting (24 MiB).
     *
     * Deliberately under MAX_UPLOAD_BYTES: the multipart envelope counts
     * towards the limit. The observed failure aborted at 26326537 bytes read
     * against a 26214400 ceiling, so a part sized exactly at the limit would
     * still be rejected.
     */
    public const PART_TARGET_BYTES = 25165824;

    /**
     * Human-readable name shown in the scheduled-tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_align_audio', 'local_aireader');
    }

    /**
     * Fetch the asset's mp3 and align it.
     */
    public function execute() {
        $data = (array)($this->get_custom_data() ?? []);
        $assetid = (int)($data['assetid'] ?? 0);
        if ($assetid <= 0) {
            mtrace('local_aireader: align_audio missing assetid, skipping');
            return;
        }

        $asset = asset_manager::get_by_id($assetid);
        if (!$asset) {
            mtrace("local_aireader: align_audio asset {$assetid} not found, skipping");
            return;
        }
        if ($asset->status !== asset_manager::STATUS_READY) {
            mtrace("local_aireader: align_audio asset {$assetid} not ready (status={$asset->status}), skipping");
            return;
        }
        if (!get_config('local_aireader', 'enable_alignment')) {
            mtrace("local_aireader: align_audio enabled=0, skipping asset {$assetid}");
            return;
        }
        if (segment_manager::has_for_asset($assetid)) {
            mtrace("local_aireader: align_audio asset {$assetid} already aligned, skipping");
            return;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files((int)$asset->contextid, 'local_aireader', 'audio', $assetid, 'itemid', false);
        if (!$files) {
            mtrace("local_aireader: align_audio asset {$assetid} has no stored mp3, skipping");
            return;
        }
        $file = reset($files);
        $bytes = $file->get_content();
        if ($bytes === '' || $bytes === false) {
            mtrace("local_aireader: align_audio asset {$assetid} stored file is empty, skipping");
            return;
        }

        $model = (string)(get_config('local_aireader', 'alignment_model') ?: 'whisper-1');
        mtrace("local_aireader: aligning asset {$assetid} (" . strlen($bytes) . " bytes) via {$model}");

        try {
            $aligner = new openai_aligner();
            if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
                $segments = $this->align_in_parts(
                    $aligner, $bytes, $file->get_filename(), (string)$asset->lang, $assetid);
                if ($segments === null) {
                    // Unsplittable audio: leave any existing segments alone and
                    // finish cleanly. The narration still plays; only the
                    // karaoke highlighting is missing.
                    return;
                }
            } else {
                $segments = $aligner->align($bytes, $file->get_filename(), (string)$asset->lang);
            }
        } catch (\Throwable $e) {
            mtrace("local_aireader: align_audio failed for asset {$assetid}: " . $e->getMessage());
            // Alignment is an enhancement, not the deliverable, so a failure
            // that an identical retry cannot fix must not sit in the failed
            // task queue retrying daily forever.
            if ($e instanceof api_http_error && !api_http_error::retryable((int)$e->status)) {
                mtrace("local_aireader: asset {$assetid} alignment failure is permanent, not retrying");
                return;
            }
            throw $e;
        }

        segment_manager::store_for_asset($assetid, $segments);
        mtrace("local_aireader: aligned asset {$assetid} into " . count($segments) . ' segment(s)');
    }

    /**
     * Align an oversized narration by splitting it into uploadable parts.
     *
     * Each part is aligned on its own and the timestamps are shifted back onto
     * one timeline, so karaoke works on long chapters instead of the task
     * failing permanently.
     *
     * @param openai_aligner $aligner Alignment client.
     * @param string $bytes Raw mp3 bytes of the whole narration.
     * @param string $filename Stored filename, used to name the parts.
     * @param string $lang Language hint passed through to the endpoint.
     * @param int $assetid Asset id, for logging.
     * @return array|null Merged segments, or null when the audio could not be split.
     */
    private function align_in_parts(
        openai_aligner $aligner,
        string $bytes,
        string $filename,
        string $lang,
        int $assetid
    ): ?array {
        $parts = mp3_splitter::split($bytes, self::PART_TARGET_BYTES);
        if (!$parts) {
            mtrace("local_aireader: asset {$assetid} audio could not be split, skipping alignment");
            return null;
        }

        $stem = preg_replace('/\.mp3$/i', '', $filename);
        mtrace("local_aireader: asset {$assetid} split into " . count($parts) . ' part(s) for alignment');

        $aligned = [];
        foreach ($parts as $i => $part) {
            $partname = $stem . '-part' . ($i + 1) . '.mp3';
            $aligned[] = [
                'segments' => $aligner->align($part['bytes'], $partname, $lang),
                'duration' => $part['duration'],
            ];
        }

        return segment_stitcher::stitch($aligned);
    }
}
