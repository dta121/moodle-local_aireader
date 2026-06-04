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
 * Restore support for local_aireader narration overrides.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores local_aireader per-resource enable/disable overrides for a
 * supported activity (mod_page, mod_book).
 *
 * Insertion is deferred to {@see after_restore_module()} so that mod_book's
 * `book_chapter` id mappings are guaranteed to exist before chapter-level
 * overrides (chapterid > 0) are remapped.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_aireader_plugin extends restore_local_plugin {
    /** @var array Parsed override rows held until module mappings are ready. */
    protected $overrides = [];

    /** @var array Parsed completion rows held until module mappings are ready. */
    protected $completions = [];

    /**
     * Declare the paths this plugin restores within a module's backup.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element(
                'aireader_override',
                $this->get_pathfor('/aireader_overrides/aireader_override')
            ),
            new restore_path_element(
                'aireader_completion',
                $this->get_pathfor('/aireader_completions/aireader_completion')
            ),
        ];
    }

    /**
     * Collect each override row; actual insertion happens in after_restore_module().
     *
     * @param array|object $data Parsed XML data for one override.
     */
    public function process_aireader_override($data) {
        $this->overrides[] = (object)$data;
    }

    /**
     * Collect each completion config row; insertion happens in after_restore_module().
     *
     * @param array|object $data Parsed XML data for one completion config.
     */
    public function process_aireader_completion($data) {
        $this->completions[] = (object)$data;
    }

    /**
     * Insert collected overrides once the activity and its chapter mappings exist.
     */
    protected function after_restore_module() {
        global $DB;

        if (empty($this->overrides) && empty($this->completions)) {
            return;
        }

        $newcmid = $this->task->get_moduleid();
        $newcourseid = $this->task->get_courseid();

        foreach ($this->overrides as $data) {
            $chapterid = (int)$data->chapterid;
            if ($chapterid > 0) {
                // Remap to the restored chapter; skip if that chapter wasn't
                // part of this restore (e.g. a single-chapter import).
                $mapped = $this->get_mappingid('book_chapter', $chapterid);
                if (!$mapped) {
                    continue;
                }
                $chapterid = (int)$mapped;
            }

            // The (cmid, chapterid) scope is unique; don't duplicate a row that a
            // form-submit default may already have created for the new module.
            if ($DB->record_exists('local_aireader_override', ['cmid' => $newcmid, 'chapterid' => $chapterid])) {
                continue;
            }

            $usermodified = 0;
            if (!empty($data->usermodified)) {
                $usermodified = (int)$this->get_mappingid('user', $data->usermodified);
            }

            $DB->insert_record('local_aireader_override', (object)[
                'courseid'     => $newcourseid,
                'cmid'         => $newcmid,
                'chapterid'    => $chapterid,
                'enabled'      => (int)$data->enabled,
                'timemodified' => $this->apply_date_offset((int)$data->timemodified),
                'usermodified' => $usermodified,
            ]);
        }

        foreach ($this->completions as $data) {
            if ($DB->record_exists('local_aireader_completion', ['cmid' => $newcmid])) {
                continue;
            }

            $usermodified = 0;
            if (!empty($data->usermodified)) {
                $usermodified = (int)$this->get_mappingid('user', $data->usermodified);
            }

            $DB->insert_record('local_aireader_completion', (object)[
                'courseid'     => $newcourseid,
                'cmid'         => $newcmid,
                'enabled'      => (int)$data->enabled,
                'threshold'    => \local_aireader\manager\completion_manager::normalize_threshold((int)$data->threshold),
                'timemodified' => $this->apply_date_offset((int)$data->timemodified),
                'usermodified' => $usermodified,
            ]);
        }
    }
}
