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
 * CLI: clear AI Reader ad hoc tasks that have run out of retry attempts.
 *
 * Those rows show "Next run: Never" in Site administration > Server > Tasks >
 * Ad hoc tasks. Cron will never pick them up again, and while they exist they
 * silently block every attempt to re-queue narration for the same asset.
 * Removing them is what makes those narrations regenerable again.
 *
 * Since 1.8.1 the reap_dead_tasks scheduled task does this hourly on its own;
 * this script is for clearing a backlog immediately without waiting for cron.
 *
 * Deletes task rows, and lifts the retry cool-down on the assets they named so
 * the work is picked up on the next page view. No other asset field is changed
 * and no stored audio file is touched.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'dry-run' => false,
        'help'    => false,
    ],
    [
        'n' => 'dry-run',
        'h' => 'help',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    echo <<<EOT
Clear AI Reader ad hoc tasks that have exhausted their retry attempts.

These are the tasks showing "Next run: Never". They will never run again, and
they block re-queueing work for the assets they name. Deleting them lets fresh
work be scheduled with a full attempt budget:

  generate_audio  re-queued when someone next opens the page, or straight away
                  by an admin pressing Regenerate in the player.
  align_audio     re-queued when someone next opens the page, because the asset
                  is already "ready" and only a missing-segments check brings
                  alignment back. (On releases before 1.8.1 nothing re-queued
                  alignment at all.)

The retry cool-down on those assets is lifted too, so the re-queue is not held
back by a backoff set while the dead row was still in the way. Audio files are
never touched, and no other asset field is changed.

Run with --dry-run first to see exactly what would be deleted.

Options:
  -n, --dry-run   Report what would be deleted and change nothing.
  -h, --help      Print this help.

Example:
  php local/aireader/cli/clear_dead_tasks.php --dry-run

EOT;
    exit(0);
}

$dryrun = (bool)$options['dry-run'];
$rows = \local_aireader\task\dead_task_cleaner::clear($dryrun);

if (!$rows) {
    cli_writeln('No AI Reader tasks are out of attempts. Nothing to do.');
    exit(0);
}

cli_heading($dryrun
    ? 'Dry run: these AI Reader tasks would be deleted'
    : 'Deleted these AI Reader tasks');

foreach ($rows as $row) {
    $first = $row->firststartingtime > 0 ? userdate($row->firststartingtime) : 'never started';
    $asset = $row->assetid > 0
        ? "asset {$row->assetid} (" . ($row->assetstatus ?? 'asset row no longer exists') . ')'
        : 'no assetid in payload';
    cli_writeln("  task {$row->id}  {$row->classname}  {$asset}  first attempt: {$first}");
}

$count = count($rows);
cli_writeln('');
cli_writeln($dryrun
    ? "{$count} task row(s) would be deleted. Re-run without --dry-run to delete them."
    : "{$count} task row(s) deleted. No audio was removed.");

if (!$dryrun) {
    $classes = array_unique(array_map(static function (\stdClass $row): string {
        return $row->classname;
    }, $rows));

    cli_writeln('Retry cool-downs on the listed assets were lifted. What happens next:');
    foreach ($classes as $class) {
        if (strpos($class, 'generate_audio') !== false) {
            cli_writeln('  generate_audio: narration is re-queued when someone next opens the '
                . 'page, or straight away if an admin presses Regenerate.');
        } else if (strpos($class, 'align_audio') !== false) {
            cli_writeln('  align_audio: the asset is already "ready" and plays fine; only the '
                . 'karaoke highlighting is missing. Alignment is re-queued when someone next '
                . 'opens the page. Regenerate would also work but re-pays for the whole '
                . 'narration, so it is the more expensive route.');
        } else {
            cli_writeln("  {$class}: re-queued by whichever flow creates it.");
        }
    }
}

exit(0);
