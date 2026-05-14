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
 * Scheduled task that removes stale local_aireader asset rows older than retention.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use core\task\scheduled_task;
use local_aireader\manager\asset_manager;

/**
 * Garbage-collect stale narration assets older than the configured retention.
 *
 * Reads `local_aireader/stale_retention_days` (default 14, 0 disables the
 * sweep). Each run deletes up to {@see self::BATCH_LIMIT} stale rows by
 * ascending timemodified, so a large backlog drains gradually rather than
 * stalling cron.
 *
 * @package local_aireader
 */
class purge_stale_assets extends scheduled_task {
    /** @var int Soft per-run cap. */
    public const BATCH_LIMIT = 500;

    /**
     * Human-readable task name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_stale', 'local_aireader');
    }

    /**
     * Run the sweep.
     */
    public function execute(): void {
        $days = (int)get_config('local_aireader', 'stale_retention_days');
        if ($days <= 0) {
            mtrace('local_aireader: stale_retention_days <= 0; sweep disabled.');
            return;
        }
        $seconds = $days * DAYSECS;
        mtrace("local_aireader: purging stale assets older than {$days} days (cutoff " . $seconds . "s)...");
        $purged = asset_manager::purge_stale_older_than($seconds, self::BATCH_LIMIT);
        mtrace("local_aireader: purged {$purged} stale asset row(s) (cap " . self::BATCH_LIMIT . ').');
    }
}
