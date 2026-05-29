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
 * Sortable, paged, downloadable table of generated narration assets.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\output;

use local_aireader\manager\cost_calculator;
use moodle_url;
use html_writer;

/**
 * Renders one row per local_aireader_asset, joining course and activity context
 * and deriving an estimated cost per asset.
 *
 * @package local_aireader
 */
class log_table extends \table_sql {
    /**
     * Build the table, its columns, headers, and base SQL.
     *
     * @param string $uniqueid A unique identifier for this table instance.
     * @param string $statusfilter Optional status to filter on (one of the
     *                             asset_manager STATUS_* values), or '' for all.
     * @param moodle_url $baseurl The page URL used for sorting/paging links.
     */
    public function __construct(string $uniqueid, string $statusfilter, moodle_url $baseurl) {
        parent::__construct($uniqueid);

        $this->define_baseurl($baseurl);
        $this->define_columns([
            'coursename', 'activity', 'lang', 'model', 'status',
            'timegenerated', 'bytesize', 'cost', 'lasterror',
        ]);
        $this->define_headers([
            get_string('report_col_course', 'local_aireader'),
            get_string('report_col_activity', 'local_aireader'),
            get_string('report_col_language', 'local_aireader'),
            get_string('report_col_model', 'local_aireader'),
            get_string('report_col_status', 'local_aireader'),
            get_string('report_col_generated', 'local_aireader'),
            get_string('report_col_size', 'local_aireader'),
            get_string('report_col_cost', 'local_aireader'),
            get_string('report_col_error', 'local_aireader'),
        ]);

        // Computed/joined columns can't be ordered by a single stored field.
        $this->no_sorting('activity');
        $this->no_sorting('cost');
        $this->no_sorting('lasterror');
        $this->sortable(true, 'timegenerated', SORT_DESC);
        $this->collapsible(false);

        $fields = 'a.id, a.courseid, a.cmid, a.module, a.instanceid, a.chapterid,
                   a.lang, a.voice, a.model, a.status, a.bytesize, a.inputchars,
                   a.lasterror, a.timecreated, a.lastgenerated,
                   COALESCE(a.lastgenerated, a.timecreated) AS timegenerated,
                   c.fullname AS coursename, c.shortname AS courseshortname,
                   COALESCE(pg.name, bk.name) AS activity,
                   bc.title AS chaptertitle';
        $from = '{local_aireader_asset} a
                 JOIN {course} c ON c.id = a.courseid
            LEFT JOIN {page} pg ON pg.id = a.instanceid AND a.module = :modpage
            LEFT JOIN {book} bk ON bk.id = a.instanceid AND a.module = :modbook
            LEFT JOIN {book_chapters} bc ON bc.id = a.chapterid';
        $where = '1 = 1';
        $params = ['modpage' => 'page', 'modbook' => 'book'];
        if ($statusfilter !== '') {
            $where .= ' AND a.status = :status';
            $params['status'] = $statusfilter;
        }
        $this->set_sql($fields, $from, $where, $params);
    }

    /**
     * Course column: a link to the course, or its short name when downloading.
     *
     * @param \stdClass $row Asset row with course fields.
     * @return string
     */
    public function col_coursename($row): string {
        $name = format_string($row->coursename, true, ['context' => \context_system::instance()]);
        if ($this->is_downloading()) {
            return $name;
        }
        $url = new moodle_url('/course/view.php', ['id' => $row->courseid]);
        return html_writer::link($url, $name);
    }

    /**
     * Activity column: the page/book name (plus chapter title for books),
     * linked to the activity when not downloading.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_activity($row): string {
        $context = \context_module::instance($row->cmid, IGNORE_MISSING) ?: \context_system::instance();
        $name = $row->activity !== null
            ? format_string($row->activity, true, ['context' => $context])
            : get_string('report_activity_missing', 'local_aireader');
        if (!empty($row->chaptertitle)) {
            $chapter = format_string($row->chaptertitle, true, ['context' => $context]);
            $name .= ' — ' . $chapter;
        }
        if ($this->is_downloading()) {
            return $name;
        }
        $url = new moodle_url('/mod/' . $row->module . '/view.php', ['id' => $row->cmid]);
        return html_writer::link($url, $name);
    }

    /**
     * Language column.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_lang($row): string {
        return s($row->lang);
    }

    /**
     * Model column, with the voice shown underneath.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_model($row): string {
        if ($this->is_downloading()) {
            return $row->model . ' / ' . $row->voice;
        }
        return s($row->model) . html_writer::empty_tag('br')
            . html_writer::tag('small', s($row->voice), ['class' => 'text-muted']);
    }

    /**
     * Status column rendered as a coloured badge (plain text when downloading).
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_status($row): string {
        $label = get_string('report_status_' . $row->status, 'local_aireader');
        if ($this->is_downloading()) {
            return $label;
        }
        $classes = [
            'ready'      => 'badge bg-success text-white',
            'error'      => 'badge bg-danger text-white',
            'generating' => 'badge bg-info text-white',
            'pending'    => 'badge bg-secondary text-white',
            'stale'      => 'badge bg-warning text-dark',
        ];
        $class = $classes[$row->status] ?? 'badge bg-secondary text-white';
        return html_writer::tag('span', $label, ['class' => $class]);
    }

    /**
     * When the asset was generated (falls back to created time for rows that
     * never reached "ready").
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_timegenerated($row): string {
        if (empty($row->timegenerated)) {
            return '—';
        }
        if ($this->is_downloading()) {
            return userdate($row->timegenerated, get_string('strftimedatetime', 'langconfig'));
        }
        return userdate($row->timegenerated);
    }

    /**
     * File size of the generated mp3.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_bytesize($row): string {
        if (empty($row->bytesize)) {
            return '—';
        }
        return display_size((int)$row->bytesize);
    }

    /**
     * Estimated OpenAI cost for this asset.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_cost($row): string {
        // Cost only makes sense for a successfully generated asset.
        if ($row->status !== 'ready') {
            return '—';
        }
        $usd = cost_calculator::estimate_usd($row->model, $row->inputchars !== null ? (int)$row->inputchars : null);
        return cost_calculator::format_usd($usd);
    }

    /**
     * Failure reason for errored rows; a dash otherwise.
     *
     * @param \stdClass $row Asset row.
     * @return string
     */
    public function col_lasterror($row): string {
        if ($row->status !== 'error' || empty($row->lasterror)) {
            return '—';
        }
        $message = (string)$row->lasterror;
        if ($this->is_downloading()) {
            return $message;
        }
        // Keep the cell compact but expose the full message on hover.
        $short = shorten_text($message, 120);
        return html_writer::tag('span', s($short), [
            'title' => s($message),
            'class' => 'text-danger',
        ]);
    }
}
