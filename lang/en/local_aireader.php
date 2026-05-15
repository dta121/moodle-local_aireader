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
 * Language strings for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aireader:listen'] = 'Listen to AI-generated narration';
$string['aireader:manage'] = 'Manage and regenerate AI-generated narration';
$string['aireader:purge'] = 'Purge AI narration assets';
$string['default_disclosure'] = 'Audio narration is AI-generated. The voice you hear is synthetic, not a real person.';
$string['default_prompt'] = 'Read this course content as a calm, clear academic guide. Use a warm, patient, professional tone with precise enunciation. Pace naturally and pause briefly at headings, paragraph breaks, and lists. Slightly emphasize key terms, definitions, and important instructions. Do not sound theatrical, promotional, or overly dramatic. Maintain a steady, trustworthy delivery throughout.';
$string['default_translation_prompt'] = 'You are an academic translator. Translate the following text from {source} to {target}. Preserve all proper nouns, technical terminology, code identifiers, mathematical notation, and chemical or biological names exactly as written. Maintain paragraph breaks and sentence structure. Do not add commentary, headings, or explanations — output only the translation.';
$string['error_empty_content'] = 'Activity content is empty after extraction.';
$string['error_no_apikey'] = 'OpenAI API key is not configured for local_aireader.';
$string['error_alignment_empty_input'] = 'Alignment was given empty audio input.';
$string['error_alignment_empty_response'] = 'Whisper returned no segments.';
$string['error_alignment_http'] = 'Whisper alignment request failed: HTTP {$a->status}: {$a->body}';
$string['error_translation_empty'] = 'Translation model returned an empty result.';
$string['error_translation_http'] = 'Translation request failed: HTTP {$a->status}: {$a->body}';
$string['error_tts_empty'] = 'TTS response was empty.';
$string['error_tts_http'] = 'TTS request failed: HTTP {$a->status}: {$a->body}';
$string['form_enabled_book'] = 'Enable AI narration on this book';
$string['form_enabled_book_help'] = 'When on, learners see a "Listen to this content" player at the top of each chapter of this book. Individual chapters can still be turned off from the player itself. Turn the whole book off here if most chapters are video.';
$string['form_enabled_page'] = 'Enable AI narration on this page';
$string['form_enabled_page_help'] = 'When on, learners see a "Listen to this content" player at the top of this page. Turn it off for pages that are mostly video, where narration would not add value.';
$string['form_section'] = 'AI narration';
$string['media_cue_audio'] = 'An audio recording is embedded at this point in the original lesson.';
$string['media_cue_audio_titled'] = 'An audio recording titled "{$a->title}" is embedded at this point in the original lesson.';
$string['media_cue_embed'] = 'An interactive element is embedded at this point in the original lesson. Please view it on the page.';
$string['media_cue_embed_titled'] = 'An interactive element titled "{$a->title}" is embedded at this point in the original lesson. Please view it on the page.';
$string['media_cue_video'] = 'A video appears at this point in the original lesson. Please watch it on the page, then return for the rest.';
$string['media_cue_video_titled'] = 'A video titled "{$a->title}" appears at this point in the original lesson. Please watch it on the page, then return for the rest.';
$string['pluginname'] = 'AI Reader';
$string['privacy:metadata:openai'] = 'Cleaned activity text is sent to the configured OpenAI-compatible endpoints for translation and text-to-speech synthesis. Personally-identifying user data (name, email, user id) is never sent.';
$string['privacy:metadata:openai:lang'] = 'The target language code for translation, if not the site default.';
$string['privacy:metadata:openai:text'] = 'The cleaned text of the Page or Book chapter being narrated.';
$string['privacy:metadata:override'] = 'Per-resource enable/disable overrides set by instructors. The user who last toggled the override is recorded.';
$string['privacy:metadata:override:chapterid'] = 'Book chapter id, or 0 for the activity-level default.';
$string['privacy:metadata:override:cmid'] = 'Course module id.';
$string['privacy:metadata:override:enabled'] = 'Whether narration is enabled at this scope.';
$string['privacy:metadata:override:timemodified'] = 'When the override was last changed.';
$string['privacy:metadata:override:usermodified'] = 'The user who last changed the override.';
$string['privacy:metadata:position'] = 'Per-learner playback positions in audio narrations, used to resume where the learner left off.';
$string['privacy:metadata:position:assetid'] = 'The audio asset this position belongs to.';
$string['privacy:metadata:position:position'] = 'The saved playback position in seconds.';
$string['privacy:metadata:position:timemodified'] = 'When the position was last saved.';
$string['privacy:metadata:position:userid'] = 'The learner whose position is being stored.';
$string['setting_apikey'] = 'OpenAI API key';
$string['setting_apikey_desc'] = 'Bearer key used for the speech endpoint. Stored unmasked in admin UI only.';
$string['setting_autogen'] = 'Auto-generate on teacher save';
$string['setting_autogen_desc'] = 'Queue regeneration when the source Page or Book is updated.';
$string['setting_chunksize'] = 'Max characters per TTS chunk';
$string['setting_chunksize_desc'] = 'Long inputs are split on sentence boundaries up to this length.';
$string['setting_alignmentendpoint'] = 'Alignment endpoint';
$string['setting_alignmentendpoint_desc'] = 'Whisper transcription endpoint. Defaults to https://api.openai.com/v1/audio/transcriptions. Reuses the OpenAI API key configured above.';
$string['setting_alignmentmodel'] = 'Alignment model';
$string['setting_alignmentmodel_desc'] = 'Whisper model used for sentence-level alignment. Defaults to whisper-1.';
$string['setting_disclosure'] = 'AI disclosure text';
$string['setting_disclosure_desc'] = 'Shown beneath the player. Required by some TTS providers.';
$string['setting_eagerlanguages'] = 'Pre-generate every enabled language on save';
$string['setting_eagerlanguages_desc'] = 'When on, saving a Page or Book chapter queues narration generation for every language in the allowlist below. Off (default) generates each language lazily on the first learner request — cheaper, but the first listener in a given language waits for synthesis. Requires "Auto-generate on teacher save" to also be on.';
$string['setting_enable_book'] = 'Enable on Books';
$string['setting_enable_book_desc'] = 'Default behaviour for new books. Individual books and chapters can still override this from their settings.';
$string['setting_enable_page'] = 'Enable on Pages';
$string['setting_enable_page_desc'] = 'Default behaviour for new pages. Individual pages can still override this from their settings.';
$string['setting_enabled'] = 'Enable plugin';
$string['setting_enabled_desc'] = 'Master switch for AI reader.';
$string['setting_enablealignment'] = 'Enable Whisper alignment (karaoke highlighting)';
$string['setting_enablealignment_desc'] = 'When on, after each audio is generated a Whisper job transcribes it with sentence-level timestamps. This powers the transcript pane and the in-place highlight effect. Adds roughly 10% to the per-asset generation cost (about $0.006 per minute of audio). One-time per asset, cached forever.';
$string['setting_enabledlanguages'] = 'Languages offered to learners';
$string['setting_enabledlanguages_desc'] = 'Comma-separated Moodle language codes (e.g. en,es,fr,pt,zh_cn). Learners see a language picker on the player listing exactly these. Leave as "en" to hide the picker and serve only one language. The site default language ({$a}) is always the source language; the others trigger a translation pass.';
$string['setting_endpoint'] = 'OpenAI speech endpoint';
$string['setting_endpoint_desc'] = 'Defaults to https://api.openai.com/v1/audio/speech. Override for proxies.';
$string['setting_heading_alignment'] = 'Transcript and karaoke highlighting';
$string['setting_heading_languages'] = 'Languages and translation';
$string['setting_highlightinplace'] = 'Highlight inside the original page text when possible';
$string['setting_highlightinplace_desc'] = 'When on, the player tries to highlight the actual rendered page text (using <mark> spans) as the audio plays. Falls back to the transcript pane on translated narrations, pages with embedded video, or pages where Moodle filters mangle the DOM enough to break matching. Off: always use the transcript pane.';
$string['setting_model'] = 'TTS model';
$string['setting_model_desc'] = 'OpenAI speech model id, for example gpt-4o-mini-tts.';
$string['setting_pollinterval'] = 'Browser poll interval (seconds)';
$string['setting_pollinterval_desc'] = 'How often the player asks the backend for an updated status.';
$string['setting_prompt'] = 'Narration prompt';
$string['setting_prompt_desc'] = 'Instructions sent to the TTS model on how to read the content.';
$string['setting_staleretention'] = 'Stale audio retention (days)';
$string['setting_staleretention_desc'] = 'Stale narration assets older than this are deleted by the daily cleanup task. Default 14. Set to 0 to disable cleanup and keep stale assets forever.';
$string['setting_translationendpoint'] = 'Translation endpoint';
$string['setting_translationendpoint_desc'] = 'Chat-completion endpoint used for translation. Defaults to https://api.openai.com/v1/chat/completions. Reuses the OpenAI API key configured above.';
$string['setting_translationmodel'] = 'Translation model';
$string['setting_translationmodel_desc'] = 'Chat-completion model id used for translation. Defaults to gpt-4o-mini.';
$string['setting_translationprompt'] = 'Translation system prompt';
$string['setting_translationprompt_desc'] = 'System prompt sent before each translation. Supports {source} and {target} placeholders, which are replaced with the language names. Keep the "preserve technical terms" guidance unless you have a specific reason to relax it.';
$string['setting_voice'] = 'Voice';
$string['setting_voice_desc'] = 'OpenAI voice name. Defaults to marin.';
$string['status_error'] = 'Audio generation failed.';
$string['status_generating'] = 'Generating audio…';
$string['status_pending'] = 'Audio is being prepared…';
$string['status_ready'] = 'Ready to play.';
$string['status_stale'] = 'Content updated; refreshing audio…';
$string['task_align_audio'] = 'Align AI narration audio with Whisper';
$string['task_generate_audio'] = 'Generate AI narration audio';
$string['task_purge_stale'] = 'Purge stale AI narration assets';
