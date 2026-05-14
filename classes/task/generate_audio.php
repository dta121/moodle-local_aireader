<?php
namespace local_aireader\task;

use core\task\adhoc_task;
use local_aireader\manager\asset_manager;
use local_aireader\manager\content_extractor;
use local_aireader\manager\openai_client;
use local_aireader\manager\storage;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad hoc task that turns a pending/stale asset row into a stored mp3.
 */
class generate_audio extends adhoc_task {

    public function get_name(): string {
        return get_string('task_generate_audio', 'local_aireader');
    }

    public function execute() {
        $data = (array)($this->get_custom_data() ?? []);
        $assetid = (int)($data['assetid'] ?? 0);
        if ($assetid <= 0) {
            mtrace('local_aireader: missing assetid in task payload, skipping');
            return;
        }

        $asset = asset_manager::get_by_id($assetid);
        if (!$asset) {
            mtrace("local_aireader: asset {$assetid} not found, skipping");
            return;
        }
        if ($asset->status === asset_manager::STATUS_READY) {
            mtrace("local_aireader: asset {$assetid} already ready, skipping");
            return;
        }

        asset_manager::update_status($asset->id, asset_manager::STATUS_GENERATING);

        try {
            // Reload canonical text. If content drifted between queue and run,
            // recompute the hash and bail out - a fresh row will be queued
            // when the next viewer hits the page.
            $extracted = content_extractor::extract(
                $asset->module,
                (int)$asset->cmid,
                $asset->chapterid ? (int)$asset->chapterid : null
            );
            $currenthash = asset_manager::compute_hash(
                $asset->module,
                (int)$asset->cmid,
                $asset->chapterid ? (int)$asset->chapterid : null,
                $asset->lang,
                $asset->voice,
                $asset->model,
                $extracted['text']
            );
            if ($currenthash !== $asset->sourcehash) {
                asset_manager::update_status(
                    $asset->id,
                    asset_manager::STATUS_STALE,
                    'Content changed between queue and run; new row will be created on next view.'
                );
                return;
            }

            $chunksize = (int)(get_config('local_aireader', 'chunk_size') ?: 3800);
            $chunks = openai_client::chunk_text($extracted['text'], $chunksize);
            if (!$chunks) {
                throw new \moodle_exception('error_empty_content', 'local_aireader');
            }

            $instructions = (string)(get_config('local_aireader', 'narration_prompt')
                ?: get_string('default_prompt', 'local_aireader'));

            $client = new openai_client();
            $audio = '';
            foreach ($chunks as $i => $chunk) {
                mtrace("local_aireader: asset {$asset->id} chunk " . ($i + 1) . '/' . count($chunks));
                // Naive concatenation of mp3 frames. Works for downstream players;
                // re-encode in a follow-up if duration metadata becomes important.
                $audio .= $client->synthesize($chunk, $asset->model, $asset->voice, $instructions);
            }

            $file = storage::store_mp3((int)$asset->id, (int)$asset->contextid, $audio);
            asset_manager::record_generated(
                (int)$asset->id,
                (int)$file->get_id(),
                (int)$file->get_filesize(),
                null
            );
            mtrace("local_aireader: asset {$asset->id} generated ({$file->get_filesize()} bytes)");
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            mtrace("local_aireader: generation failed for asset {$asset->id}: {$message}");
            asset_manager::update_status($asset->id, asset_manager::STATUS_ERROR, $message);
            // Surface to task runner so retries/backoff apply.
            throw $e;
        }
    }
}
