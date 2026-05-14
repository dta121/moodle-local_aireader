<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Reader';

// Capabilities.
$string['aireader:listen']  = 'Listen to AI-generated narration';
$string['aireader:manage']  = 'Manage and regenerate AI-generated narration';
$string['aireader:purge']   = 'Purge AI narration assets';

// Settings.
$string['setting_enabled']       = 'Enable plugin';
$string['setting_enabled_desc']  = 'Master switch for AI reader.';
$string['setting_enable_page']   = 'Enable on Pages';
$string['setting_enable_page_desc'] = 'Inject the player on mod_page views.';
$string['setting_enable_book']   = 'Enable on Books';
$string['setting_enable_book_desc'] = 'Inject the player on mod_book chapter views.';
$string['setting_model']         = 'TTS model';
$string['setting_model_desc']    = 'OpenAI speech model id, for example gpt-4o-mini-tts.';
$string['setting_voice']         = 'Voice';
$string['setting_voice_desc']    = 'OpenAI voice name. Defaults to marin.';
$string['setting_apikey']        = 'OpenAI API key';
$string['setting_apikey_desc']   = 'Bearer key used for the speech endpoint. Stored unmasked in admin UI only.';
$string['setting_endpoint']      = 'OpenAI speech endpoint';
$string['setting_endpoint_desc'] = 'Defaults to https://api.openai.com/v1/audio/speech. Override for proxies.';
$string['setting_chunksize']     = 'Max characters per TTS chunk';
$string['setting_chunksize_desc'] = 'Long inputs are split on sentence boundaries up to this length.';
$string['setting_pollinterval']  = 'Browser poll interval (seconds)';
$string['setting_pollinterval_desc'] = 'How often the player asks the backend for an updated status.';
$string['setting_autogen']       = 'Auto-generate on teacher save';
$string['setting_autogen_desc']  = 'Queue regeneration when the source Page or Book is updated.';
$string['setting_disclosure']    = 'AI disclosure text';
$string['setting_disclosure_desc'] = 'Shown beneath the player. Required by some TTS providers.';
$string['setting_prompt']        = 'Narration prompt';
$string['setting_prompt_desc']   = 'Instructions sent to the TTS model on how to read the content.';

// Defaults.
$string['default_disclosure'] = 'Audio narration is AI-generated. The voice you hear is synthetic, not a real person.';
$string['default_prompt']     = 'Read this course content as a calm, clear academic guide. Use a warm, patient, professional tone with precise enunciation. Pace naturally and pause briefly at headings, paragraph breaks, and lists. Slightly emphasize key terms, definitions, and important instructions. Do not sound theatrical, promotional, or overly dramatic. Maintain a steady, trustworthy delivery throughout.';

// Statuses.
$string['status_pending']    = 'Audio is being prepared…';
$string['status_generating'] = 'Generating audio…';
$string['status_ready']      = 'Ready to play.';
$string['status_error']      = 'Audio generation failed.';
$string['status_stale']      = 'Content updated; refreshing audio…';

// Tasks.
$string['task_generate_audio'] = 'Generate AI narration audio';

// Errors.
$string['error_no_apikey']     = 'OpenAI API key is not configured for local_aireader.';
$string['error_tts_http']      = 'TTS request failed: HTTP {$a->status}: {$a->body}';
$string['error_tts_empty']     = 'TTS response was empty.';
$string['error_empty_content'] = 'Activity content is empty after extraction.';
