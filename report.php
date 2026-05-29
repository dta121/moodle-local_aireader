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
 * Audio generation log: which narrations were generated, for what, when, the
 * estimated cost, and where generation failed and why.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_aireader\manager\asset_manager;
use local_aireader\manager\cost_calculator;
use local_aireader\output\log_table;

$status   = optional_param('status', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$perpage  = optional_param('perpage', 50, PARAM_INT);

admin_externalpage_setup('local_aireader_log');

$validstatuses = [
    asset_manager::STATUS_READY,
    asset_manager::STATUS_ERROR,
    asset_manager::STATUS_GENERATING,
    asset_manager::STATUS_PENDING,
    asset_manager::STATUS_STALE,
];
if ($status !== '' && !in_array($status, $validstatuses, true)) {
    $status = '';
}

$baseurl = new moodle_url('/local/aireader/report.php', $status !== '' ? ['status' => $status] : []);

$table = new log_table('local_aireader_log', $status, $baseurl);
$table->is_downloading($download, 'aireader-audio-log', get_string('report_pluginname', 'local_aireader'));

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('report_pluginname', 'local_aireader'));

    // Headline summary: counts by status and total estimated spend across all
    // assets (independent of the table's status filter).
    $sql = 'SELECT model, status, COUNT(*) AS cnt, SUM(COALESCE(inputchars, 0)) AS chars
              FROM {local_aireader_asset}
          GROUP BY model, status';
    $aggregates = $DB->get_recordset_sql($sql);

    $counts = array_fill_keys($validstatuses, 0);
    $total = 0;
    $totalcost = 0.0;
    $hasunknowncost = false;
    foreach ($aggregates as $agg) {
        $cnt = (int)$agg->cnt;
        $total += $cnt;
        if (isset($counts[$agg->status])) {
            $counts[$agg->status] += $cnt;
        }
        if ($agg->status === asset_manager::STATUS_READY) {
            $chars = (int)$agg->chars;
            if ($chars > 0) {
                $totalcost += (float)cost_calculator::estimate_usd($agg->model, $chars);
            } else if ($cnt > 0) {
                $hasunknowncost = true;
            }
        }
    }
    $aggregates->close();

    $a = (object)[
        'total'   => $total,
        'ready'   => $counts[asset_manager::STATUS_READY],
        'failed'  => $counts[asset_manager::STATUS_ERROR],
        'pending' => $counts[asset_manager::STATUS_PENDING] + $counts[asset_manager::STATUS_GENERATING],
        'stale'   => $counts[asset_manager::STATUS_STALE],
        'cost'    => cost_calculator::format_usd($totalcost),
    ];

    $summary = html_writer::tag('p', get_string('report_summary_counts', 'local_aireader', $a));
    $costline = get_string('report_summary_cost', 'local_aireader', $a);
    if ($hasunknowncost) {
        $costline .= ' ' . get_string('report_summary_cost_partial', 'local_aireader');
    }
    $summary .= html_writer::tag('p', $costline);
    $summary .= html_writer::tag(
        'p',
        get_string('report_cost_note', 'local_aireader'),
        ['class' => 'text-muted small']
    );
    echo $OUTPUT->box($summary, 'generalbox');

    // Status filter.
    $options = ['' => get_string('report_filter_all', 'local_aireader')];
    foreach ($validstatuses as $s) {
        $options[$s] = get_string('report_status_' . $s, 'local_aireader');
    }
    $select = new single_select(
        new moodle_url('/local/aireader/report.php'),
        'status',
        $options,
        $status,
        null
    );
    $select->label = get_string('report_filter_status', 'local_aireader');
    echo $OUTPUT->render($select);
}

$table->out($perpage, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
