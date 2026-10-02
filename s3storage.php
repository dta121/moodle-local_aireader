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
 * S3 storage status, connection check and one-click migration of existing audio.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_aireader\manager\s3_storage;
use local_aireader\task\migrate_s3;

admin_externalpage_setup('local_aireader_storage');

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$pageurl = new moodle_url('/local/aireader/s3storage.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_aireader']);

if ($action === 'test' && confirm_sesskey()) {
    try {
        $key = s3_storage::test_connection();
        redirect($pageurl, get_string('s3_test_ok', 'local_aireader', s($key)), null, notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect(
            $pageurl,
            get_string('s3_test_failed', 'local_aireader', s($e->getMessage())),
            null,
            notification::NOTIFY_ERROR
        );
    }
}

if ($action === 'migrate' && $confirm && confirm_sesskey()) {
    try {
        $count = s3_storage::start_migration();
        redirect($pageurl, get_string('s3_migrate_queued', 'local_aireader', $count), null, notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect($pageurl, s($e->getMessage()), null, notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('s3_page_title', 'local_aireader'));

if ($action === 'migrate' && !$confirm) {
    echo $OUTPUT->confirm(
        get_string('s3_migrate_confirm', 'local_aireader'),
        new moodle_url($pageurl, ['action' => 'migrate', 'confirm' => 1, 'sesskey' => sesskey()]),
        $pageurl
    );
    echo $OUTPUT->footer();
    exit;
}

$configured = true;
try {
    $destination = s3_storage::destination();
} catch (\moodle_exception $e) {
    $configured = false;
    echo $OUTPUT->notification(
        get_string('s3_not_configured', 'local_aireader', $settingsurl->out(false)),
        notification::NOTIFY_WARNING
    );
}

$mode = s3_storage::mode();
$summary = s3_storage::summary();

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->colclasses = ['', 'text-end'];
$table->data = [
    [get_string('setting_storagemode', 'local_aireader'), get_string('storage_mode_' . $mode, 'local_aireader')],
];
if ($configured) {
    $location = 's3://' . $destination['bucket'] . '/' . $destination['prefix'];
    $table->data[] = [
        get_string('s3_destination', 'local_aireader'),
        html_writer::tag('code', s($location)) . ' (' . s($destination['region']) . ')',
    ];
}
$table->data = array_merge($table->data, [
    [get_string('s3_count_local', 'local_aireader'), $summary['local']],
    [get_string('s3_count_mirrored', 'local_aireader'), $summary['mirrored']],
    [get_string('s3_count_remote', 'local_aireader'), $summary['remote']],
    [get_string('s3_count_localbytes', 'local_aireader'), display_size($summary['bytes'])],
    [get_string('s3_count_remaining', 'local_aireader'), $summary['remaining']],
    [get_string('s3_count_failed', 'local_aireader'), $summary['failed']],
    [get_string('s3_count_pendingdelete', 'local_aireader'), $summary['pendingdelete']],
]);
echo html_writer::table($table);

$queued = $DB->record_exists('task_adhoc', ['classname' => '\\' . migrate_s3::class]);
$done = $mode === s3_storage::MODE_PRIMARY && $configured && !$summary['remaining'] && $summary['remote'];
if ($queued) {
    echo $OUTPUT->notification(get_string('s3_migrate_running', 'local_aireader'), notification::NOTIFY_INFO);
} else if ($done) {
    echo $OUTPUT->notification(get_string('s3_migrate_done', 'local_aireader'), notification::NOTIFY_SUCCESS);
}

if ($configured) {
    echo html_writer::start_div('d-flex flex-wrap gap-2 my-3');
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'test']),
        get_string('s3_test_button', 'local_aireader'),
        'post'
    );
    if (!$queued && !$done) {
        echo $OUTPUT->single_button(
            new moodle_url($pageurl, ['action' => 'migrate']),
            get_string('s3_migrate_button', 'local_aireader'),
            'get',
            ['type' => 'primary']
        );
    }
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('s3_migrate_help', 'local_aireader'), ['class' => 'text-muted']);
}

$errors = $DB->get_records_select(
    'local_aireader_asset',
    's3lasterror IS NOT NULL',
    [],
    'id',
    'id, s3lasterror, s3retryafter',
    0,
    20
);
if ($errors) {
    echo $OUTPUT->heading(get_string('s3_recent_errors', 'local_aireader'), 3);
    $errtable = new html_table();
    $errtable->attributes['class'] = 'generaltable';
    $errtable->head = [
        get_string('s3_col_asset', 'local_aireader'),
        get_string('s3_col_error', 'local_aireader'),
        get_string('s3_col_retry', 'local_aireader'),
    ];
    $format = get_string('strftimedatetimeshort', 'langconfig');
    foreach ($errors as $row) {
        $errtable->data[] = [
            $row->id,
            s($row->s3lasterror),
            $row->s3retryafter ? userdate($row->s3retryafter, $format) : '-',
        ];
    }
    echo html_writer::table($errtable);
}

echo html_writer::tag('p', html_writer::link($settingsurl, get_string('s3_settings_link', 'local_aireader')));
echo $OUTPUT->footer();
