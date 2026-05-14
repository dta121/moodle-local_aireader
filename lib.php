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
 * File serving callback and Moodle integration hooks for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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

    require_login();

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = '/';

    $asset = $DB->get_record('local_aireader_asset', ['id' => $itemid], '*', MUST_EXIST);

    $sourcecontext = \context_module::instance($asset->cmid);
    require_capability('local/aireader:listen', $sourcecontext);

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
