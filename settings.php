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
}
