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
 * may access across a course, one language (and voice) per ZIP.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_aireader\manager\asset_manager;
use local_aireader\manager\download_manager;
use local_aireader\manager\openai_client;
use local_aireader\manager\openai_translator;

$courseid = required_param('id', PARAM_INT);
$dodownload = optional_param('download', 0, PARAM_BOOL);
$requestedlang = optional_param('audiolang', '', PARAM_ALPHANUMEXT);
$requestedvoice = optional_param('voice', '', PARAM_ALPHANUMEXT);

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

if (!download_manager::downloads_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('downloadcourse_heading', 'local_aireader'));
    echo $OUTPUT->notification(get_string('downloadcourse_disabled', 'local_aireader'), 'info');
    echo $OUTPUT->footer();
    die;
}

$allitems = download_manager::collect_for_course($course, (int)$USER->id);

// One language and voice per download: bundling every language multiplied
// the archive by the number of languages generated (CPIT-458).
$lang = download_manager::choose_language($allitems, $requestedlang, current_language());
$voices = $lang !== null ? download_manager::voices_in($allitems, $lang) : [];
$voice = $lang !== null ? download_manager::choose_voice($allitems, $lang, $requestedvoice) : null;
$items = $lang !== null ? download_manager::filter_items($allitems, $lang, $voice) : [];

if ($dodownload && $items) {
    require_sesskey();
    download_manager::serve_zip($course, $items, $lang);
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

$langname = openai_translator::language_display_name($lang);
$langoptions = [];
foreach (download_manager::languages_in($allitems) as $code) {
    $langoptions[$code] = openai_translator::language_display_name($code);
}
echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3', ['style' => 'gap: 1rem;']);
$langselect = new single_select($pageurl, 'audiolang', $langoptions, $lang, null);
$langselect->set_label(get_string('downloadcourse_language', 'local_aireader'));
echo $OUTPUT->render($langselect);
if (count($voices) > 1) {
    $voiceoptions = [];
    foreach ($voices as $id) {
        $voiceoptions[$id] = openai_client::voice_display_name($id);
    }
    $voiceselect = new single_select(new moodle_url($pageurl, ['audiolang' => $lang]), 'voice', $voiceoptions, $voice, null);
    $voiceselect->set_label(get_string('downloadcourse_voice', 'local_aireader'));
    echo $OUTPUT->render($voiceselect);
}
echo html_writer::end_div();

$table = new html_table();
$table->head = [
    get_string('downloadcourse_col_activity', 'local_aireader'),
    get_string('downloadcourse_col_language', 'local_aireader'),
    get_string('downloadcourse_col_size', 'local_aireader'),
];
$table->attributes['class'] = 'generaltable local-aireader-downloadlist';
$defaultvoice = asset_manager::default_voice();
foreach ($items as $item) {
    $label = $item->activityname;
    if ($item->chaptertitle !== '') {
        $label .= ' — ' . $item->chaptertitle;
    }
    $langcell = core_text::strtoupper($item->lang);
    if ($item->voice !== '' && $item->voice !== $defaultvoice) {
        $langcell .= ' — ' . openai_client::voice_display_name($item->voice);
    }
    $table->data[] = [
        s($label),
        s($langcell),
        display_size($item->bytesize),
    ];
}
echo html_writer::table($table);

$a = (object)[
    'count' => count($items),
    'size'  => display_size($totalbytes),
    'language' => $langname,
];
echo html_writer::tag('p', get_string('downloadcourse_total', 'local_aireader', $a), ['class' => 'font-weight-bold']);

if ($threshold > 0 && $totalbytes >= $threshold) {
    echo $OUTPUT->notification(
        get_string('downloadcourse_warn', 'local_aireader', display_size($totalbytes)),
        'warning'
    );
}

$downloadurl = new moodle_url('/local/aireader/download_course.php', [
    'id'        => $courseid,
    'audiolang' => $lang,
    'voice'     => $voice,
    'download'  => 1,
    'sesskey'   => sesskey(),
]);
echo $OUTPUT->single_button(
    $downloadurl,
    get_string('downloadcourse_button', 'local_aireader', $a),
    'get'
);

echo $OUTPUT->footer();
