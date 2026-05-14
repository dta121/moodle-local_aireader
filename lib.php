<?php
// File serving callback and Moodle integration hooks for local_aireader.

defined('MOODLE_INTERNAL') || die();

/**
 * Serve generated mp3 files via pluginfile.php, gated by access to the source module.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context  $context
 * @param string   $filearea
 * @param array    $args  [itemid, filename] where itemid is local_aireader_asset.id
 * @param bool     $forcedownload
 * @param array    $options
 * @return void
 */
function local_aireader_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($filearea !== 'audio') {
        send_file_not_found();
    }

    require_login();

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = '/';

    $asset = $DB->get_record('local_aireader_asset', ['id' => $itemid], '*', MUST_EXIST);

    // Enforce access against the *source* module context, not the storage context.
    $sourcecontext = \context_module::instance($asset->cmid);
    require_capability('local/aireader:listen', $sourcecontext);

    // Make sure the user can also see the underlying activity.
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

    // No public caching; force per-request auth.
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
