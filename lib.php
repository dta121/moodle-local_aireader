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

    \local_aireader\manager\asset_manager::assert_asset_visible($asset, $sourcecontext);

    // Stored audio for a disabled scope behaves as if it does not exist, the
    // same gate download_manager applies when packaging the course ZIP.
    if (!\local_aireader\manager\asset_manager::is_narration_available(
        (string)$asset->module,
        (int)$asset->cmid,
        (int)$asset->chapterid,
        $sourcecontext
    )) {
        send_file_not_found();
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

    // Give downloads a human-readable filename (course - activity[ - chapter]
    // (lang).mp3) rather than the internal asset-N.mp3, so offline files are
    // recognisable. Streaming playback is unaffected.
    if ($forcedownload && empty($options['filename'])) {
        $namebits = [
            format_string($sourcecourse->shortname),
            format_string($sourcecm->name),
        ];
        if (!empty($asset->chapterid)) {
            $chaptertitle = $DB->get_field('book_chapters', 'title', ['id' => (int)$asset->chapterid]);
            if (!empty($chaptertitle)) {
                $namebits[] = format_string($chaptertitle);
            }
        }
        $label = implode(' - ', array_filter($namebits));
        $options['filename'] = clean_filename($label . ' (' . $asset->lang . ').mp3');
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
    global $PAGE;

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

    if (\local_aireader\manager\completion_manager::site_enabled()) {
        $completion = $cmid > 0
            ? \local_aireader\manager\completion_manager::get_config_for_cm($cmid)
            : null;
        $completionenabled = $completion ? (int)$completion->enabled : 0;
        $threshold = $completion
            ? \local_aireader\manager\completion_manager::normalize_threshold((int)$completion->threshold)
            : \local_aireader\manager\completion_manager::DEFAULT_THRESHOLD;

        $mform->addElement(
            'selectyesno',
            'local_aireader_completion_enabled',
            get_string('form_completion_enabled', 'local_aireader')
        );
        $mform->addHelpButton('local_aireader_completion_enabled', 'form_completion_enabled', 'local_aireader');
        $mform->setDefault('local_aireader_completion_enabled', $completionenabled);

        $mform->addElement(
            'text',
            'local_aireader_completion_threshold',
            get_string('form_completion_threshold', 'local_aireader'),
            ['size' => 3]
        );
        $mform->setType('local_aireader_completion_threshold', PARAM_INT);
        $mform->setDefault('local_aireader_completion_threshold', $threshold);
        $mform->addHelpButton('local_aireader_completion_threshold', 'form_completion_threshold', 'local_aireader');
        $mform->disabledIf('local_aireader_completion_threshold', 'local_aireader_completion_enabled', 'eq', 0);

        $mform->addElement(
            'static',
            'local_aireader_completion_note',
            '',
            get_string('form_completion_note', 'local_aireader')
        );
        $mform->hideIf('local_aireader_completion_note', 'local_aireader_completion_enabled', 'eq', 0);

        $PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var init = function() {
        var enabled = document.querySelector('[name="local_aireader_completion_enabled"]');
        if (!enabled) {
            return;
        }
        var form = enabled.closest('form');
        if (!form) {
            return;
        }
        form.addEventListener('submit', function() {
            var existing = form.querySelector('[data-local-aireader-completion-shim="1"]');
            if (existing) {
                existing.remove();
            }
            var selected = form.querySelector('[name="completion"]:checked');
            var view = form.querySelector('[name="completionview"]');
            if (enabled.value !== '1' || !selected || selected.value !== '2' || (view && view.checked)) {
                return;
            }
            var shim = document.createElement('input');
            shim.type = 'hidden';
            shim.name = 'completionview';
            shim.value = '1';
            shim.setAttribute('data-local-aireader-completion-shim', '1');
            form.appendChild(shim);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
});
JS);
    }
}

/**
 * Persist the form value after a mod_page or mod_book activity is saved.
 *
 * @param stdClass $data Submitted form data, including the new coursemodule id.
 * @param stdClass $course Course record.
 * @return stdClass Possibly-modified data (we just pass through).
 */
function local_aireader_coursemodule_edit_post_actions($data, $course) {
    global $USER;

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
    if (isset($data->local_aireader_completion_enabled)) {
        // Mirror Moodle's completion-lock behaviour: once completion data
        // exists, avoid changing this local completion rule from the form post.
        if (property_exists($data, 'completionunlocked') && empty($data->completionunlocked)) {
            return $data;
        }
        \local_aireader\manager\completion_manager::set_config(
            (int)$course->id,
            (int)$data->coursemodule,
            (bool)(int)$data->local_aireader_completion_enabled,
            (int)($data->local_aireader_completion_threshold ?? \local_aireader\manager\completion_manager::DEFAULT_THRESHOLD),
            (int)($data->usermodified ?? $USER->id ?? 0)
        );
        if ((bool)(int)$data->local_aireader_completion_enabled) {
            \local_aireader\manager\completion_manager::enforce_cm_completion_mode(
                (int)$course->id,
                (int)$data->coursemodule
            );
            $data->completion = COMPLETION_TRACKING_AUTOMATIC;
            $data->completionview = COMPLETION_VIEW_NOT_REQUIRED;
            $data->completiongradeitemnumber = null;
            $data->completionpassgrade = 0;
        }
    }
    return $data;
}

/**
 * Add a "Download narration audio" link to the course navigation.
 *
 * Shown to any user who can listen when the plugin and downloads are enabled.
 * The link is intentionally lightweight — it does not pre-scan the course for
 * ready assets on every navigation render; the target page shows an empty
 * state when there is nothing to download.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course object.
 * @param context_course $context The course context.
 * @return void
 */
function local_aireader_extend_navigation_course($navigation, $course, $context): void {
    if (!get_config('local_aireader', 'enabled')) {
        return;
    }
    if (get_config('local_aireader', 'allow_downloads') === '0') {
        return;
    }
    if (!has_capability('local/aireader:listen', $context)) {
        return;
    }

    $url = new moodle_url('/local/aireader/download_course.php', ['id' => $course->id]);
    $navigation->add(
        get_string('downloadcourse_nav', 'local_aireader'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_aireader_downloadcourse',
        new pix_icon('t/download', '')
    );
}
