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
 * File serving, mod-form injection and other Moodle integration hooks for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve generated mp3 files via pluginfile.php, gated by access to the source module.
 *
 * @param stdClass $course        The course object.
 * @param stdClass $cm            Course module the URL was framed against (may be null).
 * @param context  $context       Context the URL was framed against.
 * @param string   $filearea      The file area requested.
 * @param array    $args          Trailing URL args [itemid, filename].
 * @param bool     $forcedownload Whether to force download rather than inline display.
 * @param array    $options       Extra options for send_stored_file.
 * @return void
 */
function local_aireader_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): void {
    global $DB;

    if ($filearea !== 'audio') {
        send_file_not_found();
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = '/';

    $asset = $DB->get_record('local_aireader_asset', ['id' => $itemid], '*', MUST_EXIST);

    // Resolve the asset's source course and cm so require_login can enforce
    // enrolment, course visibility, and activity availability (date restrictions,
    // group membership, completion gates) — not just session login.
    $sourcecourse = get_course($asset->courseid);
    $sourcecm = get_coursemodule_from_id('', $asset->cmid, 0, false, MUST_EXIST);
    require_login($sourcecourse, false, $sourcecm);

    $sourcecontext = \context_module::instance($asset->cmid);
    require_capability('local/aireader:listen', $sourcecontext);

    // Hidden-chapter gate for book assets: cm-level access doesn't imply the
    // user can see every chapter, so re-check the chapter the audio belongs to.
    if (!empty($asset->chapterid)) {
        \local_aireader\manager\asset_manager::assert_chapter_visible(
            $sourcecm,
            (int)$asset->chapterid,
            $sourcecontext
        );
    }

    $modinfo = get_fast_modinfo($asset->courseid);
    if (!isset($modinfo->cms[$asset->cmid]) || !$modinfo->cms[$asset->cmid]->uservisible) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $file = $fs->get_file(
        $sourcecontext->id,
        'local_aireader',
        'audio',
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Inject an "Enable AI narration" field into mod_page and mod_book edit forms.
 *
 * Moodle calls this for every coursemodule edit form site-wide; we no-op for
 * unsupported modules.
 *
 * @param moodleform_mod $formwrapper The mod form wrapper.
 * @param MoodleQuickForm $mform The underlying QuickForm.
 * @return void
 */
function local_aireader_coursemodule_standard_elements($formwrapper, $mform): void {
    if (!get_config('local_aireader', 'enabled')) {
        return;
    }
    $modname = $formwrapper->get_current()->modulename ?? '';
    if (!in_array($modname, ['page', 'book'], true)) {
        return;
    }
    $globalkey = $modname === 'book' ? 'enable_book' : 'enable_page';
    if (!get_config('local_aireader', $globalkey)) {
        return;
    }

    $cm = $formwrapper->get_coursemodule();
    $cmid = $cm ? (int)$cm->id : 0;

    $current = null;
    if ($cmid > 0) {
        $current = \local_aireader\manager\override_manager::get($cmid, 0);
    }
    $default = $current === null ? 1 : ($current ? 1 : 0);

    $mform->addElement('header', 'local_aireader_section', get_string('form_section', 'local_aireader'));
    $mform->addElement(
        'selectyesno',
        'local_aireader_enabled',
        get_string('form_enabled_' . $modname, 'local_aireader')
    );
    $mform->addHelpButton('local_aireader_enabled', 'form_enabled_' . $modname, 'local_aireader');
    $mform->setDefault('local_aireader_enabled', $default);
}

/**
 * Persist the form value after a mod_page or mod_book activity is saved.
 *
 * @param stdClass $data Submitted form data, including the new coursemodule id.
 * @param stdClass $course Course record.
 * @return stdClass Possibly-modified data (we just pass through).
 */
function local_aireader_coursemodule_edit_post_actions($data, $course) {
    if (!isset($data->local_aireader_enabled)) {
        return $data;
    }
    if (empty($data->coursemodule)) {
        return $data;
    }
    \local_aireader\manager\override_manager::set(
        (int)$course->id,
        (int)$data->coursemodule,
        0,
        (bool)(int)$data->local_aireader_enabled
    );
    return $data;
}
