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
 * Ad hoc task that generates audio for a pending or stale asset row.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use core\task\adhoc_task;
use core\task\manager as task_manager;
use local_aireader\manager\asset_manager;
use local_aireader\manager\content_extractor;
use local_aireader\manager\id3_writer;
use local_aireader\manager\openai_client;
use local_aireader\manager\openai_translator;
use local_aireader\exception\api_http_error;
use local_aireader\manager\storage;
use local_aireader\manager\translation_manager;
use local_aireader\manager\tts_splitter;
use local_aireader\task\align_audio;

/**
 * Ad hoc task that turns a pending or stale asset row into a stored mp3.
 *
 * @package local_aireader
 */
class generate_audio extends adhoc_task {
    /**
     * Human-readable task name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_generate_audio', 'local_aireader');
    }

    /**
     * Generate audio for the asset id passed in custom data.
     *
     * @return void
     */
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

            // Refuse runaway pages before we start spending OpenAI budget: a
            // teacher can otherwise queue an arbitrarily large synthesis job
            // (megabytes of HTML → hours of cron + dollars in API calls).
            $maxchars = asset_manager::max_narration_chars();
            $textlen = mb_strlen($extracted['text']);
            if ($maxchars > 0 && $textlen > $maxchars) {
                $msg = "Narration text exceeds max ({$textlen} > {$maxchars} chars)";
                mtrace("local_aireader: asset {$asset->id} {$msg}; refusing to generate");
                asset_manager::update_status($asset->id, asset_manager::STATUS_ERROR, $msg);
                return;
            }

            // Translate before TTS when the asset's language doesn't match the site source.
            global $CFG;
            $sourcelang = (string)($CFG->lang ?? 'en');
            $narrationtext = $extracted['text'];
            if (!translation_manager::is_same_language($sourcelang, (string)$asset->lang)) {
                $translationmodel = (string)(get_config('local_aireader', 'translation_model') ?: 'gpt-5-mini');
                mtrace("local_aireader: asset {$asset->id} translating {$sourcelang} -> {$asset->lang} via {$translationmodel}");
                $translator = new openai_translator();
                $narrationtext = translation_manager::get_or_translate(
                    $extracted['text'],
                    $sourcelang,
                    (string)$asset->lang,
                    $translationmodel,
                    static function (string $text, string $src, string $tgt) use ($translator): string {
                        return $translator->translate($text, $src, $tgt);
                    }
                );
            }

            // Token-capped models need a tighter character ceiling than the
            // configured default; see openai_client::chunk_size_for().
            $chunksize = openai_client::chunk_size_for(
                (string)$asset->model,
                (int)get_config('local_aireader', 'chunk_size')
            );
            $chunks = openai_client::chunk_text($narrationtext, $chunksize);
            if (!$chunks) {
                throw new \moodle_exception('error_empty_content', 'local_aireader');
            }

            $instructions = (string)(get_config('local_aireader', 'narration_prompt')
                ?: get_string('default_prompt', 'local_aireader'));

            $client = new openai_client();
            $audio = '';
            foreach ($chunks as $i => $chunk) {
                mtrace("local_aireader: asset {$asset->id} chunk " . ($i + 1) . '/' . count($chunks));
                // No local token count can be trusted for arbitrary translated
                // content, so let the endpoint be the authority: if it rejects
                // the chunk as too long, split it and retry the pieces.
                $audio .= tts_splitter::synthesize_split(
                    static function (string $piece) use ($client, $asset, $instructions): string {
                        return $client->synthesize($piece, $asset->model, $asset->voice, $instructions);
                    },
                    $chunk,
                    $chunksize
                );
            }

            // Embed ID3 metadata so downloaded files are recognisable in a
            // music player and carry the AI-generated disclosure offline.
            $audio = id3_writer::tag_mp3($audio, $this->build_id3_tags($asset));

            $file = storage::store_mp3((int)$asset->id, (int)$asset->contextid, $audio);
            asset_manager::record_generated(
                (int)$asset->id,
                (int)$file->get_id(),
                (int)$file->get_filesize(),
                null,
                \core_text::strlen($narrationtext)
            );
            mtrace("local_aireader: asset {$asset->id} generated ({$file->get_filesize()} bytes)");

            // Chain Whisper alignment as a separate task so the audio is
            // immediately playable; karaoke lights up as soon as alignment finishes.
            if (get_config('local_aireader', 'enable_alignment')) {
                $aligntask = new align_audio();
                $aligntask->set_custom_data(['assetid' => (int)$asset->id]);
                task_manager::queue_adhoc_task($aligntask, true);
                mtrace("local_aireader: queued align_audio for asset {$asset->id}");
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            mtrace("local_aireader: generation failed for asset {$asset->id}: {$message}");
            asset_manager::update_status($asset->id, asset_manager::STATUS_ERROR, $message);
            // Rethrowing a permanent failure makes Moodle retry it daily
            // forever, so the task never leaves the failed queue and the error
            // is reported over and over. The asset already carries the error
            // for the dashboard, so swallow what an identical retry cannot fix.
            if ($e instanceof api_http_error && !api_http_error::retryable((int)$e->status)) {
                mtrace("local_aireader: asset {$asset->id} failure is permanent, not retrying");
                return;
            }
            throw $e;
        }
    }

    /**
     * Resolve human-readable ID3 tag values for an asset.
     *
     * Album is the course (so a whole course groups together in a library),
     * artist is the site, and the title is the activity — suffixed with the
     * chapter for books and with a language marker for non-source narrations.
     * The comment carries the configured AI disclosure.
     *
     * @param \stdClass $asset local_aireader_asset row.
     * @return array Tag map consumed by {@see id3_writer::tag_mp3()}.
     */
    private function build_id3_tags(\stdClass $asset): array {
        global $DB, $CFG;

        $course = get_course((int)$asset->courseid);
        $album = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);

        $activityname = '';
        $cm = get_coursemodule_from_id('', (int)$asset->cmid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $activityname = format_string($cm->name);
        }
        $title = $activityname !== '' ? $activityname : $album;

        $track = '';
        if (!empty($asset->chapterid)) {
            $chapter = $DB->get_record('book_chapters', ['id' => (int)$asset->chapterid], 'title, pagenum');
            if ($chapter) {
                if (!empty($chapter->title)) {
                    $chaptertitle = format_string($chapter->title);
                    $title = $activityname !== '' ? $activityname . ': ' . $chaptertitle : $chaptertitle;
                }
                if (!empty($chapter->pagenum)) {
                    $track = (string)(int)$chapter->pagenum;
                }
            }
        }

        // Keep different-language narrations of the same activity distinct in a library.
        $sourcelang = (string)($CFG->lang ?? 'en');
        if (!translation_manager::is_same_language($sourcelang, (string)$asset->lang)) {
            $title .= ' (' . \core_text::strtoupper((string)$asset->lang) . ')';
        }

        $disclosure = trim((string)get_config('local_aireader', 'disclosure'));
        if ($disclosure === '') {
            $disclosure = get_string('default_disclosure', 'local_aireader');
        }

        return [
            'title'   => $title,
            'artist'  => format_string(get_site()->fullname),
            'album'   => $album,
            'genre'   => 'Speech',
            'track'   => $track,
            'comment' => $disclosure,
        ];
    }
}
