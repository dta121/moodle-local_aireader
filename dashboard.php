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
 * AI Reader usage dashboard: adoption, health, language demand, reach, storage
 * and estimated spend at a glance, derived entirely from stored data.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\chart_bar;
use core\chart_line;
use core\chart_pie;
use core\chart_series;
use local_aireader\manager\cost_calculator;
use local_aireader\manager\dashboard_metrics;

admin_externalpage_setup('local_aireader_dashboard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_dashboard_pluginname', 'local_aireader'));

$summary = dashboard_metrics::site_summary();

if ($summary->totalassets === 0) {
    echo $OUTPUT->notification(get_string('dashboard_empty', 'local_aireader'), 'info');
    echo $OUTPUT->footer();
    return;
}

// KPI strip. Each entry is [label, formatted value].
$failuredisplay = $summary->failurepercent === null
    ? get_string('dashboard_notapplicable', 'local_aireader')
    : format_float($summary->failurepercent, 1) . '%';
$kpis = [
    [get_string('dashboard_kpi_ready', 'local_aireader'), (string)$summary->readyassets],
    [get_string('dashboard_kpi_minutes', 'local_aireader'), format_float($summary->audiominutes, 1)],
    [get_string('dashboard_kpi_cost', 'local_aireader'), cost_calculator::format_usd($summary->estcost)],
    [get_string('dashboard_kpi_reach', 'local_aireader'), (string)$summary->reach],
    [get_string('dashboard_kpi_activities', 'local_aireader'), (string)$summary->activitiesnarrated],
    [get_string('dashboard_kpi_failure', 'local_aireader'), $failuredisplay],
    [get_string('dashboard_kpi_storage', 'local_aireader'), display_size($summary->storagebytes)],
    [get_string('dashboard_kpi_optouts', 'local_aireader'), (string)$summary->optouts],
];

$cards = '';
foreach ($kpis as [$label, $value]) {
    $cards .= html_writer::div(
        html_writer::div(s($value), 'h3 mb-0') .
        html_writer::div(s($label), 'text-muted small'),
        'card p-3 m-2 text-center',
        ['style' => 'min-width: 9rem; flex: 1 1 9rem;']
    );
}
echo html_writer::div($cards, 'd-flex flex-wrap');

if ($summary->hasunknowncost) {
    echo html_writer::tag(
        'p',
        get_string('report_summary_cost_partial', 'local_aireader'),
        ['class' => 'text-muted small']
    );
}
echo html_writer::tag('p', get_string('dashboard_reach_note', 'local_aireader'), ['class' => 'text-muted small']);

// Assets-by-status pie (non-zero statuses only).
$statuscounts = dashboard_metrics::status_breakdown();
$statuslabels = [];
$statusvalues = [];
foreach ($statuscounts as $status => $count) {
    if ($count <= 0) {
        continue;
    }
    $statuslabels[] = get_string('report_status_' . $status, 'local_aireader');
    $statusvalues[] = $count;
}
if ($statusvalues) {
    $pie = new chart_pie();
    $pie->set_title(get_string('dashboard_chart_status', 'local_aireader'));
    $pie->add_series(new chart_series(get_string('dashboard_series_assets', 'local_aireader'), $statusvalues));
    $pie->set_labels($statuslabels);
    echo $OUTPUT->render($pie);
}

// Narrations-by-language bar.
$langdemand = dashboard_metrics::language_demand();
if ($langdemand) {
    $bar = new chart_bar();
    $bar->set_title(get_string('dashboard_chart_language', 'local_aireader'));
    $bar->add_series(new chart_series(
        get_string('dashboard_series_narrations', 'local_aireader'),
        array_values($langdemand)
    ));
    $bar->set_labels(array_map(static fn($code) => core_text::strtoupper($code), array_keys($langdemand)));
    echo $OUTPUT->render($bar);
}

// Adoption-over-time line (ready narrations generated per month).
$adoption = dashboard_metrics::adoption_over_time();
if ($adoption) {
    $line = new chart_line();
    $line->set_title(get_string('dashboard_chart_adoption', 'local_aireader'));
    $line->add_series(new chart_series(
        get_string('dashboard_series_narrations', 'local_aireader'),
        array_values($adoption)
    ));
    $line->set_labels(array_keys($adoption));
    echo $OUTPUT->render($line);
}

// Links out to the detailed reports.
$links = html_writer::link(
    new moodle_url('/local/aireader/report.php'),
    get_string('report_pluginname', 'local_aireader')
);
$links .= ' &middot; ';
$links .= html_writer::link(
    new moodle_url('/local/aireader/costs.php'),
    get_string('report_costbycourse_link', 'local_aireader')
);
echo html_writer::tag('p', $links);

echo $OUTPUT->footer();
