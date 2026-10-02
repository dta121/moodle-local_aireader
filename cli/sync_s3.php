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
 * Inspect or synchronize existing AI Reader audio with S3, without synthesis.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_aireader\manager\asset_manager;
use local_aireader\manager\s3_storage;

[$options, $unrecognised] = cli_get_params([
    'help' => false, 'dry-run' => false, 'status' => false, 'limit' => 25, 'assetid' => 0, 'all' => false,
], ['h' => 'help', 'n' => 'dry-run']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(', ', $unrecognised)));
}
if ($options['help']) {
    echo <<<'HELP'
Synchronize existing AI Reader audio using the configured storage mode.
No narration is generated and no OpenAI requests are made.

Options:
  --status           Show storage counts and recent safe error messages; no S3 requests.
  --dry-run, -n      List the next batch without uploading, downloading or deleting.
  --limit=N          Maximum assets and obsolete objects per batch (default 25).
  --assetid=N        Synchronize one ready asset, overriding its retry cooldown.
  --all              Keep processing batches until nothing is left to transfer
                     (use with S3 primary mode to migrate and offload all audio).
  --help, -h         Show this help.

Examples:
  php local/aireader/cli/sync_s3.php --status
  php local/aireader/cli/sync_s3.php --dry-run
  php local/aireader/cli/sync_s3.php --limit=25
  php local/aireader/cli/sync_s3.php --all --limit=100

Moodle-only mode stops new transfers. Existing remote audio remains readable.
Select mirror mode to restore local copies before disabling S3 credentials.
Obsolete object cleanup continues in every mode, using its original destination.

HELP;
    exit(0);
}

$mode = s3_storage::mode();
cli_writeln('Storage mode: ' . $mode);
if ($options['status']) {
    foreach (['pending', 'ready', 'delete'] as $status) {
        cli_writeln('S3 objects ' . $status . ': ' . $DB->count_records('local_aireader_s3', ['status' => $status]));
    }
    cli_writeln('Remote-only assets: ' . $DB->count_records_select(
        'local_aireader_asset',
        'fileid IS NULL AND s3objectid IS NOT NULL'
    ));
    foreach ($DB->get_records_select('local_aireader_asset', 's3lasterror IS NOT NULL', [], 'id', '*', 0, 20) as $asset) {
        cli_writeln('Asset ' . $asset->id . ': ' . $asset->s3lasterror);
    }
    foreach ($DB->get_records_select('local_aireader_s3', 'lasterror IS NOT NULL', [], 'id', '*', 0, 20) as $object) {
        cli_writeln('S3 object ' . $object->id . ' (' . $object->status . '): ' . $object->lasterror);
    }
    exit(0);
}

$limit = filter_var($options['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$assetid = filter_var($options['assetid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($limit === false || $assetid === false) {
    cli_error('Use a limit between 1 and 1000 and a non-negative assetid.');
}
try {
    if ($assetid) {
        $asset = asset_manager::get_by_id($assetid);
        if (!$asset || $asset->status !== asset_manager::STATUS_READY) {
            cli_error('The requested asset does not exist or is not ready.');
        }
        $assets = $mode === s3_storage::MODE_LOCAL ? [] : [$asset];
    } else {
        $assets = s3_storage::candidates($limit);
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage());
}

if ($options['dry-run']) {
    foreach ($assets as $asset) {
        cli_writeln('Would synchronize asset ' . $asset->id . ' (' . (int)$asset->bytesize . ' bytes).');
    }
    $cleanup = $DB->count_records_select('local_aireader_s3', 'status = :status AND retryafter <= :now', [
        'status' => 'delete', 'now' => time(),
    ]);
    cli_writeln(count($assets) . ' asset(s) selected; ' . min($limit, $cleanup) . ' obsolete object(s) eligible for cleanup.');
    exit(0);
}

$failures = 0;
$selected = 0;
$tried = [];
do {
    foreach ($assets as $asset) {
        $tried[$asset->id] = true;
        $selected++;
        try {
            $synced = s3_storage::sync_asset((int)$asset->id);
            cli_writeln('Asset ' . $asset->id . ($synced ? ': synchronized.' : ': busy or no longer eligible; skipped.'));
        } catch (\moodle_exception $e) {
            $failures++;
            cli_writeln('Asset ' . $asset->id . ': ' . $e->getMessage());
        }
    }
    // With --all, keep going until a batch brings nothing new; each asset is tried once.
    $assets = ($options['all'] && !$assetid)
        ? array_slice(array_diff_key(s3_storage::candidates($limit + count($tried)), $tried), 0, $limit, true)
        : [];
} while ($assets);
cli_writeln('Obsolete objects removed: ' . s3_storage::cleanup($limit));
cli_writeln($selected . ' asset(s) selected; ' . $failures . ' transfer failure(s).');
if ($options['all']) {
    cli_writeln('Remaining to transfer (including retry cooldowns): ' . s3_storage::count_candidates());
}
exit($failures ? 1 : 0);
