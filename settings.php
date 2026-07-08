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
 * Admin settings for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Audio generation log report. Registered outside the $hassiteconfig block and
// guarded by its own capability so managers can view it without full site
// configuration access.
$ADMIN->add('reports', new admin_externalpage(
    'local_aireader_log',
    get_string('report_pluginname', 'local_aireader'),
    new moodle_url('/local/aireader/report.php'),
    'local/aireader:viewlog'
));

$ADMIN->add('reports', new admin_externalpage(
    'local_aireader_costs',
    get_string('report_costbycourse_pluginname', 'local_aireader'),
    new moodle_url('/local/aireader/costs.php'),
    'local/aireader:viewlog'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aireader', get_string('pluginname', 'local_aireader'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/enabled',
        get_string('setting_enabled', 'local_aireader'),
        get_string('setting_enabled_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/enable_page',
        get_string('setting_enable_page', 'local_aireader'),
        get_string('setting_enable_page_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/enable_book',
        get_string('setting_enable_book', 'local_aireader'),
        get_string('setting_enable_book_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/model',
        get_string('setting_model', 'local_aireader'),
        get_string('setting_model_desc', 'local_aireader'),
        'gpt-4o-mini-tts',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/voice',
        get_string('setting_voice', 'local_aireader'),
        get_string('setting_voice_desc', 'local_aireader'),
        'marin',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_aireader/openai_api_key',
        get_string('setting_apikey', 'local_aireader'),
        get_string('setting_apikey_desc', 'local_aireader'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/openai_endpoint',
        get_string('setting_endpoint', 'local_aireader'),
        get_string('setting_endpoint_desc', 'local_aireader'),
        'https://api.openai.com/v1/audio/speech',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/chunk_size',
        get_string('setting_chunksize', 'local_aireader'),
        get_string('setting_chunksize_desc', 'local_aireader'),
        3800,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/poll_interval',
        get_string('setting_pollinterval', 'local_aireader'),
        get_string('setting_pollinterval_desc', 'local_aireader'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/auto_generate_on_save',
        get_string('setting_autogen', 'local_aireader'),
        get_string('setting_autogen_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/stale_retention_days',
        get_string('setting_staleretention', 'local_aireader'),
        get_string('setting_staleretention_desc', 'local_aireader'),
        14,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/max_narration_chars',
        get_string('setting_maxnarrationchars', 'local_aireader'),
        get_string('setting_maxnarrationchars_desc', 'local_aireader'),
        50000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/allow_downloads',
        get_string('setting_allowdownloads', 'local_aireader'),
        get_string('setting_allowdownloads_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/download_warn_threshold_mb',
        get_string('setting_downloadwarnthreshold', 'local_aireader'),
        get_string('setting_downloadwarnthreshold_desc', 'local_aireader'),
        100,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/enable_completion',
        get_string('setting_enablecompletion', 'local_aireader'),
        get_string('setting_enablecompletion_desc', 'local_aireader'),
        0
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aireader/disclosure',
        get_string('setting_disclosure', 'local_aireader'),
        get_string('setting_disclosure_desc', 'local_aireader'),
        get_string('default_disclosure', 'local_aireader'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aireader/narration_prompt',
        get_string('setting_prompt', 'local_aireader'),
        get_string('setting_prompt_desc', 'local_aireader'),
        get_string('default_prompt', 'local_aireader'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_aireader/heading_appearance',
        get_string('setting_heading_appearance', 'local_aireader'),
        get_string('setting_heading_appearance_desc', 'local_aireader')
    ));

    $settings->add(new admin_setting_configselect(
        'local_aireader/player_design',
        get_string('setting_playerdesign', 'local_aireader'),
        get_string('setting_playerdesign_desc', 'local_aireader'),
        'full',
        [
            'full'      => get_string('design_full', 'local_aireader'),
            'banner'    => get_string('design_banner', 'local_aireader'),
            'pill'      => get_string('design_pill', 'local_aireader'),
            'accordion' => get_string('design_accordion', 'local_aireader'),
            'inline'    => get_string('design_inline', 'local_aireader'),
        ]
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_aireader/player_accent_color',
        get_string('setting_accentcolor', 'local_aireader'),
        get_string('setting_accentcolor_desc', 'local_aireader'),
        '#f86a01'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/autoplay_on_expand',
        get_string('setting_autoplayonexpand', 'local_aireader'),
        get_string('setting_autoplayonexpand_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_aireader/heading_cost',
        get_string('setting_heading_cost', 'local_aireader'),
        get_string('setting_heading_cost_desc', 'local_aireader')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aireader/pricing',
        get_string('setting_pricing', 'local_aireader'),
        get_string('setting_pricing_desc', 'local_aireader'),
        \local_aireader\manager\cost_calculator::default_pricing(),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading(
        'local_aireader/heading_languages',
        get_string('setting_heading_languages', 'local_aireader'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/enabled_languages',
        get_string('setting_enabledlanguages', 'local_aireader'),
        get_string('setting_enabledlanguages_desc', 'local_aireader', $CFG->lang ?? 'en'),
        'en',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/eager_languages_on_save',
        get_string('setting_eagerlanguages', 'local_aireader'),
        get_string('setting_eagerlanguages_desc', 'local_aireader'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/translation_model',
        get_string('setting_translationmodel', 'local_aireader'),
        get_string('setting_translationmodel_desc', 'local_aireader'),
        'gpt-4o-mini',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/translation_endpoint',
        get_string('setting_translationendpoint', 'local_aireader'),
        get_string('setting_translationendpoint_desc', 'local_aireader'),
        'https://api.openai.com/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aireader/translation_prompt',
        get_string('setting_translationprompt', 'local_aireader'),
        get_string('setting_translationprompt_desc', 'local_aireader'),
        get_string('default_translation_prompt', 'local_aireader'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_aireader/heading_alignment',
        get_string('setting_heading_alignment', 'local_aireader'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/enable_alignment',
        get_string('setting_enablealignment', 'local_aireader'),
        get_string('setting_enablealignment_desc', 'local_aireader'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aireader/highlight_in_place',
        get_string('setting_highlightinplace', 'local_aireader'),
        get_string('setting_highlightinplace_desc', 'local_aireader'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/alignment_model',
        get_string('setting_alignmentmodel', 'local_aireader'),
        get_string('setting_alignmentmodel_desc', 'local_aireader'),
        'whisper-1',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aireader/alignment_endpoint',
        get_string('setting_alignmentendpoint', 'local_aireader'),
        get_string('setting_alignmentendpoint_desc', 'local_aireader'),
        'https://api.openai.com/v1/audio/transcriptions',
        PARAM_URL
    ));
}
