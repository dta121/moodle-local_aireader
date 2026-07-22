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

$string['adminui_chip_apikey'] = 'API key configured';
$string['adminui_chip_disabled'] = 'Plugin disabled';
$string['adminui_chip_enabled'] = 'Plugin enabled';
$string['adminui_chip_karaoke_off'] = 'Karaoke highlighting off';
$string['adminui_chip_karaoke_on'] = 'Karaoke highlighting on';
$string['adminui_chip_langs'] = '{$a} learner languages';
$string['adminui_chip_langs_one'] = '1 learner language';
$string['adminui_chip_noapikey'] = 'API key missing';
$string['adminui_configured'] = 'Configured';
$string['adminui_default'] = 'Default: {$a}';
$string['adminui_discard'] = 'Discard changes';
$string['adminui_editlist'] = 'Edit list ({$a} available)';
$string['adminui_filter'] = 'Filter settings…';
$string['adminui_hideadvanced'] = 'Hide advanced settings';
$string['adminui_hidelist'] = 'Hide list';
$string['adminui_modcount'] = '{$a} settings differ from Moodle defaults';
$string['adminui_modified'] = 'Modified';
$string['adminui_noresults'] = 'No settings match your filter.';
$string['adminui_reset'] = 'Reset';
$string['adminui_showadvanced'] = 'Show advanced settings ({$a})';
$string['adminui_sidebar_note'] = 'Settings marked "Modified" differ from the shipped default. Advanced settings are tucked away per section.';
$string['adminui_source'] = 'source';
$string['aireader:listen'] = 'Listen to AI-generated narration';
$string['aireader:manage'] = 'Manage and regenerate AI-generated narration';
$string['aireader:viewlog'] = 'View the AI narration audio generation log';
$string['dashboard_chart_adoption'] = 'Narrations generated over time';
$string['dashboard_chart_language'] = 'Ready narrations by language';
$string['dashboard_chart_status'] = 'Assets by status';
$string['dashboard_empty'] = 'No narration has been generated yet, so there is nothing to report. Metrics appear here once audio is generated.';
$string['dashboard_kpi_activities'] = 'Activities narrated';
$string['dashboard_kpi_cost'] = 'Estimated spend';
$string['dashboard_kpi_failure'] = 'Generation failure rate';
$string['dashboard_kpi_minutes'] = 'Audio minutes';
$string['dashboard_kpi_optouts'] = 'Instructor opt-outs';
$string['dashboard_kpi_reach'] = 'Learners reached';
$string['dashboard_kpi_ready'] = 'Ready narrations';
$string['dashboard_kpi_storage'] = 'Audio storage';
$string['dashboard_notapplicable'] = 'n/a';
$string['dashboard_reach_note'] = '"Learners reached" counts learners who have started listening (they have a saved playback position). It is not a completion figure — a saved position resets when a track finishes.';
$string['dashboard_series_assets'] = 'Assets';
$string['dashboard_series_narrations'] = 'Narrations';
$string['default_disclosure'] = 'Audio narration is AI-generated. The voice you hear is synthetic, not a real person.';
$string['default_prompt'] = 'Read this course content as a calm, clear academic guide. Use a warm, patient, professional tone with precise enunciation. Pace naturally and pause briefly at headings, paragraph breaks, and lists. Slightly emphasize key terms, definitions, and important instructions. Do not sound theatrical, promotional, or overly dramatic. Maintain a steady, trustworthy delivery throughout.';
$string['default_translation_prompt'] = 'You are a professional academic translator. Translate the following learner-facing content from {source} to {target}.

Requirements:

- Preserve the complete meaning, academic tone, pedagogical intent, paragraph breaks, headings, lists, and table structure.
- Write naturally and fluently in the target language. Do not imitate the source language\'s word order or sentence structure.
- Use established target-language terminology for academic, scientific, mathematical, technical, chemical, and biological concepts.
- Preserve numerical values, equations, mathematical notation, chemical formulas, course codes, citations, and units of measurement.
- Do not translate or modify URLs, email addresses, file paths, code, identifiers, variable names, function names, or placeholders such as {name}, {{value}}, [[token]], %s, or $1.
- Preserve proper nouns unless a well-established target-language name or transliteration exists. Never invent a localized name.
- If the input contains HTML or Markdown, preserve its structure, tags, attributes, links, and formatting. Translate only learner-facing text and human-readable accessibility text.
- Preserve existing headings. Do not add, omit, summarize, explain, or answer any part of the content.
- Return only the translated content. Do not use quotation marks, introductions, commentary, or code fences.';
$string['design_accordion'] = 'Collapsed accordion (expands on click)';
$string['design_banner'] = 'Slim banner bar';
$string['design_dockpill'] = 'Pill + docked mini-player (controls follow the learner)';
$string['design_full'] = 'Full player (current default)';
$string['design_inline'] = 'Right-aligned inline action';
$string['design_pill'] = 'Inline pill button (expands to full player)';
$string['design_slimbar'] = 'Slim bar (one row; transport appears on play)';
$string['design_slimpill'] = 'Slim pill (expands to slim bar)';
$string['download_zip_suffix'] = 'AI narration audio';
$string['downloadcourse_button'] = 'Download all ({$a})';
$string['downloadcourse_col_activity'] = 'Activity';
$string['downloadcourse_col_language'] = 'Language';
$string['downloadcourse_col_size'] = 'Size';
$string['downloadcourse_disabled'] = 'Audio downloads are turned off on this site.';
$string['downloadcourse_empty'] = 'There is no narration audio ready to download in this course yet. Audio becomes available here once it has been generated for the pages and book chapters you can access.';
$string['downloadcourse_heading'] = 'Download narration audio';
$string['downloadcourse_intro'] = 'Download the AI-generated narration for this course to listen offline. Only audio that is ready and available to you is included.';
$string['downloadcourse_nav'] = 'Download narration audio';
$string['downloadcourse_total'] = '{$a->count} files, {$a->size} total.';
$string['downloadcourse_warn'] = 'This download is large ({$a}). On a metered or slow connection it may take a while and use significant data.';
$string['error_alignment_empty_input'] = 'Alignment was given empty audio input.';
$string['error_alignment_empty_response'] = 'Whisper returned no segments.';
$string['error_alignment_http'] = 'Whisper alignment request failed: {$a}';
$string['error_asset_lock_timeout'] = 'Could not reserve the narration asset for generation. Please try again.';
$string['error_empty_content'] = 'Activity content is empty after extraction.';
$string['error_endpoint_invalid'] = 'Configured OpenAI endpoint is not a safe HTTPS URL.';
$string['error_narration_disabled'] = 'Narration is not enabled for this content.';
$string['error_no_apikey'] = 'OpenAI API key is not configured for local_aireader.';
$string['error_translation_empty'] = 'Translation model returned an empty result.';
$string['error_translation_http'] = 'Translation request failed: {$a}';
$string['error_tts_empty'] = 'TTS response was empty.';
$string['error_tts_http'] = 'TTS request failed: {$a}';
$string['form_completion_enabled'] = 'Use AI narration listening for activity completion';
$string['form_completion_enabled_help'] = 'When on, the activity is marked complete after the learner listens to the configured percentage of a generated AI Reader narration in the embedded Moodle player. Plain downloaded MP3 playback is not counted. Moodle activity completion must also be enabled for this activity.';
$string['form_completion_note'] = 'When this is on, AI Reader sets this activity to automatic completion and removes Moodle\'s native "view the activity" completion rule on save, so viewing alone does not complete it.';
$string['form_completion_threshold'] = 'Required listening percentage';
$string['form_completion_threshold_help'] = 'Enter a value from 1 to 100. Progress is based on distinct audio ranges played in the embedded Moodle player, so skipping ahead does not by itself satisfy the requirement. For Book activities, this first version applies the threshold to the chapter narration the learner plays.';
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
$string['player_ai_voice_short'] = 'AI-generated voice';
$string['player_being_prepared'] = 'Audio is being prepared…';
$string['player_could_not_load'] = 'Could not load audio.';
$string['player_download'] = 'Download audio';
$string['player_expand'] = 'Show audio player';
$string['player_generation_failed'] = 'Audio generation failed.';
$string['player_language'] = 'Language';
$string['player_listen_short'] = 'Listen';
$string['player_listen_title'] = 'Listen to this content';
$string['player_loading'] = 'Loading…';
$string['player_loading_audio'] = 'Loading audio…';
$string['player_more_options'] = 'More options';
$string['player_now_playing'] = 'Now playing · follows you as you read';
$string['player_off_here'] = 'AI narration is turned off for this {$a}.';
$string['player_offline_disabled'] = 'AI narration disabled';
$string['player_pause'] = 'Pause';
$string['player_play'] = 'Play';
$string['player_playback_blocked'] = 'Playback blocked.';
$string['player_playback_failed'] = 'Audio playback failed.';
$string['player_playback_speed'] = 'Playback speed';
$string['player_preparing_lang'] = 'Preparing in selected language…';
$string['player_preparing_transcript'] = 'Preparing transcript…';
$string['player_preparing_voice'] = 'Preparing with selected voice…';
$string['player_progress'] = 'Playback position';
$string['player_queued_for_regen'] = 'Queued for regeneration…';
$string['player_ready'] = 'Ready to play.';
$string['player_regenerate'] = 'Regenerate audio';
$string['player_restart'] = 'Restart from beginning';
$string['player_show_transcript'] = 'Show transcript';
$string['player_skip_back'] = 'Skip back 15 seconds';
$string['player_skip_forward'] = 'Skip forward 15 seconds';
$string['player_speed'] = 'Speed';
$string['player_transcript_label'] = 'Transcript';
$string['player_turn_off_here'] = 'Turn off for this {$a}';
$string['player_turn_on_here'] = 'Turn on for this {$a}';
$string['player_voice'] = 'Voice';
$string['pluginname'] = 'AI Reader';
$string['privacy:metadata:completion'] = 'Per-activity AI Reader listening completion settings. The user who last changed the setting is recorded.';
$string['privacy:metadata:completion:cmid'] = 'Course module id.';
$string['privacy:metadata:completion:enabled'] = 'Whether listening-based completion is enabled for this activity.';
$string['privacy:metadata:completion:threshold'] = 'The required listening percentage.';
$string['privacy:metadata:completion:timemodified'] = 'When the completion setting was last changed.';
$string['privacy:metadata:completion:usermodified'] = 'The user who last changed the completion setting.';
$string['privacy:metadata:listen'] = 'Per-learner listened audio ranges used for AI Reader activity completion.';
$string['privacy:metadata:listen:assetid'] = 'The audio asset this listened range belongs to.';
$string['privacy:metadata:listen:endms'] = 'The end of the listened range in milliseconds.';
$string['privacy:metadata:listen:startms'] = 'The start of the listened range in milliseconds.';
$string['privacy:metadata:listen:timemodified'] = 'When the listened range was last changed.';
$string['privacy:metadata:listen:userid'] = 'The learner whose listened range is being stored.';
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
$string['report_activity_missing'] = '(activity deleted)';
$string['report_col_activity'] = 'Activity';
$string['report_col_audios'] = 'Audios generated';
$string['report_col_cost'] = 'Est. cost';
$string['report_col_course'] = 'Course';
$string['report_col_error'] = 'Failure reason';
$string['report_col_generated'] = 'Generated';
$string['report_col_language'] = 'Language';
$string['report_col_model'] = 'Model / voice';
$string['report_col_size'] = 'Size';
$string['report_col_status'] = 'Status';
$string['report_col_totalcost'] = 'Estimated total cost';
$string['report_cost_note'] = 'Costs are estimates for budgeting only, derived from the narration character count and the per-model rates configured in the plugin settings. Each asset is priced at the rate that was in effect when it was generated. Translation and Whisper alignment are billed separately and are not included.';
$string['report_costbycourse_empty'] = 'No audio has been generated yet, so there are no course costs to report.';
$string['report_costbycourse_link'] = 'View estimated cost by course';
$string['report_costbycourse_pluginname'] = 'AI Reader cost by course';
$string['report_costbycourse_total'] = 'Total';
$string['report_costbycourse_unknownnote'] = '* This total excludes audio generated before cost tracking was added (no character count was recorded for it).';
$string['report_dashboard_pluginname'] = 'AI Reader usage dashboard';
$string['report_filter_all'] = 'All statuses';
$string['report_filter_status'] = 'Filter by status';
$string['report_pluginname'] = 'AI Reader audio log';
$string['report_status_error'] = 'Failed';
$string['report_status_generating'] = 'Generating';
$string['report_status_pending'] = 'Pending';
$string['report_status_ready'] = 'Ready';
$string['report_status_stale'] = 'Stale';
$string['report_summary_cost'] = 'Estimated total cost of generated audio: {$a->cost}.';
$string['report_summary_cost_partial'] = 'Some audio was generated before cost tracking was added and is excluded from this total.';
$string['report_summary_counts'] = '{$a->total} assets: {$a->ready} ready, {$a->failed} failed, {$a->pending} in progress, {$a->stale} stale.';
$string['scope_book'] = 'book';
$string['scope_chapter'] = 'chapter';
$string['scope_page'] = 'page';
$string['setting_accentcolor'] = 'Player accent colour';
$string['setting_accentcolor_desc'] = 'Highlight colour for the play button, progress bar, and active-text highlighting. Defaults to the Saylor orange — set it to your brand colour. Hover and tint shades are derived from it automatically.';
$string['setting_alignmentendpoint'] = 'Alignment endpoint';
$string['setting_alignmentendpoint_desc'] = 'Whisper transcription endpoint. Defaults to https://api.openai.com/v1/audio/transcriptions. Reuses the OpenAI API key configured above.';
$string['setting_alignmentmodel'] = 'Alignment model';
$string['setting_alignmentmodel_desc'] = 'Whisper model used for sentence-level alignment. Defaults to whisper-1.';
$string['setting_allowdownloads'] = 'Allow audio downloads';
$string['setting_allowdownloads_desc'] = 'When on, learners see a Download button on the player to save the narration audio for offline listening. Turn off to serve audio for streaming only.';
$string['setting_apikey'] = 'OpenAI API key';
$string['setting_apikey_desc'] = 'Bearer key used for the speech endpoint. Stored unmasked in admin UI only.';
$string['setting_autogen'] = 'Auto-generate on teacher save';
$string['setting_autogen_desc'] = 'Queue regeneration when the source Page or Book is updated.';
$string['setting_autoplayonexpand'] = 'Play automatically when a compact design is opened';
$string['setting_autoplayonexpand_desc'] = 'When a learner opens one of the compact player designs (banner, pill, accordion, inline), playback starts as soon as the audio is ready instead of requiring a second click on play. No effect on the full player. Browsers may still block autoplay until the learner has interacted with the site.';
$string['setting_chunksize'] = 'Max characters per TTS chunk';
$string['setting_chunksize_desc'] = 'Long inputs are split on sentence boundaries up to this length.';
$string['setting_disclosure'] = 'AI disclosure text';
$string['setting_disclosure_desc'] = 'Shown beneath the player. Required by some TTS providers.';
$string['setting_downloadwarnthreshold'] = 'Large download warning threshold (MB)';
$string['setting_downloadwarnthreshold_desc'] = 'On the course "Download narration audio" page, show a warning before a learner downloads a combined archive at least this large. Default 100 MB. Set to 0 to never warn.';
$string['setting_eagerlanguages'] = 'Pre-generate every enabled language on save';
$string['setting_eagerlanguages_desc'] = 'When on, saving a Page or Book chapter queues narration generation for every language in the allowlist below. Off (default) generates each language lazily on the first learner request — cheaper, but the first listener in a given language waits for synthesis. Requires "Auto-generate on teacher save" to also be on.';
$string['setting_enable_book'] = 'Enable on Books';
$string['setting_enable_book_desc'] = 'Default behaviour for new books. Individual books and chapters can still override this from their settings.';
$string['setting_enable_page'] = 'Enable on Pages';
$string['setting_enable_page_desc'] = 'Default behaviour for new pages. Individual pages can still override this from their settings.';
$string['setting_enablealignment'] = 'Enable Whisper alignment (karaoke highlighting)';
$string['setting_enablealignment_desc'] = 'When on, after each audio is generated a Whisper job transcribes it with sentence-level timestamps. This powers the transcript pane and the in-place highlight effect. Adds roughly 10% to the per-asset generation cost (about $0.006 per minute of audio). One-time per asset, cached forever.';
$string['setting_enablecompletion'] = 'Allow listening-based activity completion';
$string['setting_enablecompletion_desc'] = 'When on, teachers can configure supported Page and Book activities to be marked complete after learners listen to a chosen percentage of an AI Reader narration in the embedded Moodle player. Downloaded/offline MP3 playback is not counted in this release.';
$string['setting_enabled'] = 'Enable plugin';
$string['setting_enabled_desc'] = 'Master switch for AI reader.';
$string['setting_enabledlanguages'] = 'Languages offered to learners';
$string['setting_enabledlanguages_desc'] = 'Tick the languages learners may pick on the player. The list mirrors the languages OpenAI\'s speech models officially support; regional variants (e.g. Portuguese (Brazil)) share the same underlying model support. Tick only one language to hide the picker. The site default language ({$a}) is always the source language; the others trigger a translation pass. Each ticked language a learner requests costs a translation + TTS generation per activity, so enable only what your learners need.';
$string['setting_enabledlanguagesextra'] = 'Additional language codes';
$string['setting_enabledlanguagesextra_desc'] = 'Comma-separated Moodle language codes to offer in addition to the checklist above (e.g. so, km). Use this when OpenAI adds support for a language before this plugin\'s checklist is updated, or for regional locale codes not listed. Codes entered here are offered to learners exactly like ticked languages. Leave empty normally.';
$string['setting_enabledvoices'] = 'Voices offered to learners';
$string['setting_enabledvoices_desc'] = 'Tick the voices learners may pick on the player. The default voice above is always offered; the picker is hidden when it is the only one. Each (language, voice) combination a learner requests is a separately billed TTS generation, produced lazily on first request — audio already generated with other voices is kept and reused. Note: the older tts-1 / tts-1-hd models only accept Alloy, Echo, Fable, Nova, Onyx, and Shimmer.';
$string['setting_enabledvoicesextra'] = 'Additional voice ids';
$string['setting_enabledvoicesextra_desc'] = 'Comma-separated OpenAI voice ids to offer in addition to the checklist above. Use this when OpenAI ships a new voice before this plugin\'s checklist is updated. Leave empty normally.';
$string['setting_endpoint'] = 'OpenAI speech endpoint';
$string['setting_endpoint_desc'] = 'Defaults to https://api.openai.com/v1/audio/speech. Override for proxies.';
$string['setting_heading_content'] = 'Content & generation';
$string['setting_heading_content_desc'] = 'Where players appear by default and how narration audio gets generated.';
$string['setting_heading_cost'] = 'Cost tracking';
$string['setting_heading_cost_desc'] = 'Per-model rates used to estimate generation cost on the audio log and cost-by-course reports.';
$string['setting_heading_languages'] = 'Languages & translation';
$string['setting_heading_languages_desc'] = 'Offer narrations in more than one language.';
$string['setting_heading_player'] = 'Player';
$string['setting_heading_player_desc'] = 'How the player looks and what learners can do with it.';
$string['setting_heading_setup'] = 'Setup & connection';
$string['setting_heading_setup_desc'] = 'The essentials: turn the plugin on, connect it to OpenAI, and pick the narration voices.';
$string['setting_heading_transcript'] = 'Transcript & highlighting';
$string['setting_heading_transcript_desc'] = 'Whisper-powered transcripts and karaoke-style follow-along highlighting.';
$string['setting_highlightinplace'] = 'Highlight inside the original page text when possible';
$string['setting_highlightinplace_desc'] = 'When on, the player tries to highlight the actual rendered page text (using <mark> spans) as the audio plays. Falls back to the transcript pane on translated narrations, pages with embedded video, or pages where Moodle filters mangle the DOM enough to break matching. Off: always use the transcript pane.';
$string['setting_highlightinteractive'] = 'Make in-page highlights clickable';
$string['setting_highlightinteractive_desc'] = 'When on, learners can click the highlighted sentences in the page body to jump the audio to that point. When off, the in-page highlighting is follow-along only (no pointer or click), and click-to-seek is available only in the transcript pane. Only applies when in-page highlighting above is on.';
$string['setting_maxnarrationchars'] = 'Max narration characters per asset';
$string['setting_maxnarrationchars_desc'] = 'Hard cap on the cleaned text length sent to the TTS model for one Page or Book chapter. Generation is refused for content longer than this. Default 50000 (≈ 50 minutes of audio, ~$0.50 per asset). Lower this if you want to cap per-asset OpenAI spend more tightly; raise it for long-form lectures.';
$string['setting_model'] = 'TTS model';
$string['setting_model_desc'] = 'OpenAI speech model id, for example gpt-4o-mini-tts.';
$string['setting_playerdesign'] = 'Player design';
$string['setting_playerdesign_desc'] = 'How the player appears on the page. The full player (default) shows every control immediately. The compact designs show a small trigger that expands into the same full player when a learner clicks it.';
$string['setting_pollinterval'] = 'Browser poll interval (seconds)';
$string['setting_pollinterval_desc'] = 'How often the player asks the backend for an updated status.';
$string['setting_pricing'] = 'TTS pricing (cost per million characters)';
$string['setting_pricing_desc'] = 'One model per line in the form <code>model, rate, date</code>. The rate is US dollars per 1,000,000 narration characters. The date (YYYY-MM-DD) is optional and is the effective-from date: audio generated on or after it is priced at this rate, while audio generated earlier keeps the previously-dated rate — so past cost records stay accurate. To change a price going forward, add a new line with the new rate and the date it takes effect; do not edit past lines. A line with no date applies to any audio with no earlier dated rate. Use <code>*</code> as the model for a catch-all rate. Example:<br><code>gpt-4o-mini-tts, 10.00<br>gpt-4o-mini-tts, 12.00, 2026-07-01<br>tts-1, 15.00</code>';
$string['setting_prompt'] = 'Narration prompt';
$string['setting_prompt_desc'] = 'Instructions sent to the TTS model on how to read the content.';
$string['setting_staleretention'] = 'Stale audio retention (days)';
$string['setting_staleretention_desc'] = 'Stale narration assets older than this are deleted by the daily cleanup task. Default 14. Set to 0 to disable cleanup and keep stale assets forever.';
$string['setting_translationendpoint'] = 'Translation endpoint';
$string['setting_translationendpoint_desc'] = 'Chat-completion endpoint used for translation. Defaults to https://api.openai.com/v1/chat/completions. Reuses the OpenAI API key configured above.';
$string['setting_translationmodel'] = 'Translation model';
$string['setting_translationmodel_desc'] = 'Chat-completion model id used for translation. Defaults to gpt-5-mini.';
$string['setting_translationprompt'] = 'Translation system prompt';
$string['setting_translationprompt_desc'] = 'System prompt sent before each translation. Supports {source} and {target} placeholders, which are replaced with the language names. Keep the "preserve technical terms" guidance unless you have a specific reason to relax it.';
$string['setting_voice'] = 'Default voice';
$string['setting_voice_desc'] = 'OpenAI voice used when a learner has not picked another one. Defaults to marin. Always available to learners regardless of the checklist below.';
$string['status_error'] = 'Audio generation failed.';
$string['status_generating'] = 'Generating audio…';
$string['status_pending'] = 'Audio is being prepared…';
$string['status_ready'] = 'Ready to play.';
$string['status_stale'] = 'Content updated; refreshing audio…';
$string['task_align_audio'] = 'Align AI narration audio with Whisper';
$string['task_generate_audio'] = 'Generate AI narration audio';
$string['task_purge_stale'] = 'Purge stale AI narration assets';
