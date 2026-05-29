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
 * Per-course cost rollup: every course that used the plugin and the estimated
 * total spend on generating its narration audio.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_aireader\manager\cost_calculator;
use local_aireader\manager\cost_report;

admin_externalpage_setup('local_aireader_costs');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_costbycourse_pluginname', 'local_aireader'));

$courses = cost_report::totals_by_course();

if (empty($courses)) {
    echo $OUTPUT->notification(get_string('report_costbycourse_empty', 'local_aireader'), 'info');
    echo $OUTPUT->footer();
    return;
}

$systemcontext = context_system::instance();
$grandcost = 0.0;
$grandready = 0;
$anyunknown = false;

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('report_col_course', 'local_aireader'),
    get_string('report_col_audios', 'local_aireader'),
    get_string('report_col_totalcost', 'local_aireader'),
];
$table->colclasses = ['', 'text-end', 'text-end'];

foreach ($courses as $c) {
    $grandcost += $c->cost;
    $grandready += $c->ready;
    $anyunknown = $anyunknown || $c->hasunknowncost;

    $coursename = format_string($c->coursename, true, ['context' => $systemcontext]);
    $link = html_writer::link(new moodle_url('/course/view.php', ['id' => $c->courseid]), $coursename);

    $costcell = cost_calculator::format_usd($c->cost);
    if ($c->hasunknowncost) {
        // Mark courses whose total omits assets generated before cost tracking.
        $costcell .= ' ' . html_writer::tag('span', '*', ['class' => 'text-muted']);
    }

    $table->data[] = [$link, $c->ready, $costcell];
}

// Grand-total row.
$totalrow = new html_table_row([
    html_writer::tag('strong', get_string('report_costbycourse_total', 'local_aireader')),
    html_writer::tag('strong', $grandready),
    html_writer::tag('strong', cost_calculator::format_usd($grandcost)),
]);
$table->data[] = $totalrow;

echo html_writer::table($table);

if ($anyunknown) {
    echo html_writer::tag(
        'p',
        get_string('report_costbycourse_unknownnote', 'local_aireader'),
        ['class' => 'text-muted small']
    );
}
echo html_writer::tag(
    'p',
    get_string('report_cost_note', 'local_aireader'),
    ['class' => 'text-muted small']
);

echo $OUTPUT->footer();
