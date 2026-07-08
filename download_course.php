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
 * Course-level page: list and bulk-download the AI narration audio a learner
 * may access across a course, as a single ZIP.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_aireader\manager\download_manager;

$courseid = required_param('id', PARAM_INT);
$dodownload = optional_param('download', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($course->id);
require_capability('local/aireader:listen', $context);

$pageurl = new moodle_url('/local/aireader/download_course.php', ['id' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('downloadcourse_heading', 'local_aireader'));
$PAGE->set_heading(format_string($course->fullname));

// Site-wide kill switch: downloads may be disabled by the admin.
if (!download_manager::downloads_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('downloadcourse_heading', 'local_aireader'));
    echo $OUTPUT->notification(get_string('downloadcourse_disabled', 'local_aireader'), 'info');
    echo $OUTPUT->footer();
    die;
}

$items = download_manager::collect_for_course($course, (int)$USER->id);

// Serve the ZIP on a valid, confirmed request. serve_zip() streams and exits.
if ($dodownload && $items) {
    require_sesskey();
    download_manager::serve_zip($course, $items);
    die;
}

$totalbytes = download_manager::total_bytes($items);
$threshold = download_manager::warn_threshold_bytes();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('downloadcourse_heading', 'local_aireader'));

if (!$items) {
    echo $OUTPUT->notification(get_string('downloadcourse_empty', 'local_aireader'), 'info');
    echo $OUTPUT->footer();
    die;
}

echo html_writer::tag('p', get_string('downloadcourse_intro', 'local_aireader'));

// Per-item table so learners see exactly what they will get and how big it is.
$table = new html_table();
$table->head = [
    get_string('downloadcourse_col_activity', 'local_aireader'),
    get_string('downloadcourse_col_language', 'local_aireader'),
    get_string('downloadcourse_col_size', 'local_aireader'),
];
$table->attributes['class'] = 'generaltable local-aireader-downloadlist';
foreach ($items as $item) {
    $label = $item->activityname;
    if ($item->chaptertitle !== '') {
        $label .= ' — ' . $item->chaptertitle;
    }
    $table->data[] = [
        s($label),
        s(core_text::strtoupper($item->lang)),
        display_size($item->bytesize),
    ];
}
echo html_writer::table($table);

// Total size line, plus a warning when the archive is large.
$a = (object)[
    'count' => count($items),
    'size'  => display_size($totalbytes),
];
echo html_writer::tag('p', get_string('downloadcourse_total', 'local_aireader', $a), ['class' => 'font-weight-bold']);

if ($threshold > 0 && $totalbytes >= $threshold) {
    echo $OUTPUT->notification(
        get_string('downloadcourse_warn', 'local_aireader', display_size($totalbytes)),
        'warning'
    );
}

// Download button: a GET form carrying the sesskey so the request is CSRF-safe.
$downloadurl = new moodle_url('/local/aireader/download_course.php', [
    'id'       => $courseid,
    'download' => 1,
    'sesskey'  => sesskey(),
]);
echo $OUTPUT->single_button(
    $downloadurl,
    get_string('downloadcourse_button', 'local_aireader', display_size($totalbytes)),
    'get'
);

echo $OUTPUT->footer();
