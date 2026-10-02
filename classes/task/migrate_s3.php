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
 * One-off bulk migration of existing audio into S3.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\manager\s3_storage;

/**
 * Drain the S3 backlog faster than the five-minute scheduled sync.
 *
 * Queued from the Storage admin page. Each run transfers batches for a bounded
 * time, then re-queues itself while transferable assets remain, so a large
 * library migrates without one long-running cron process. It never generates
 * narration: assets are moved exactly as {@see sync_s3} would move them, and
 * local files are removed only after a verified upload in primary mode.
 *
 * @package local_aireader
 */
class migrate_s3 extends \core\task\adhoc_task {
    /** @var int Seconds one run may spend transferring before handing over. */
    public const TIME_BUDGET = 600;
    /** @var int Assets fetched per batch. */
    public const BATCH = 50;

    /**
     * Name shown in the task log.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_migrate_s3', 'local_aireader');
    }

    /**
     * Queue a run unless one is already waiting or running.
     *
     * @param bool $dedupe False when a running task hands over to its successor:
     *     its own task_adhoc row still exists and would otherwise block the queue.
     */
    public static function queue(bool $dedupe = true): void {
        \core\task\manager::queue_adhoc_task(new self(), $dedupe);
    }

    /**
     * Transfer batches until the backlog or the time budget runs out.
     */
    public function execute(): void {
        if (s3_storage::mode() === s3_storage::MODE_LOCAL) {
            mtrace('local_aireader: S3 migration stopped because Moodle storage is selected.');
            return;
        }
        $deadline = time() + self::TIME_BUDGET;
        $moved = 0;
        $failed = 0;
        $tried = [];
        while (time() < $deadline) {
            $batch = array_diff_key(s3_storage::candidates(self::BATCH + count($tried)), $tried);
            if (!$batch) {
                break;
            }
            foreach ($batch as $asset) {
                // Busy or failed assets stay candidates; try each only once per run.
                $tried[$asset->id] = true;
                try {
                    if (s3_storage::sync_asset((int)$asset->id)) {
                        $moved++;
                    }
                } catch (\moodle_exception $e) {
                    $failed++;
                    mtrace('local_aireader: S3 migration of asset ' . $asset->id . ' will retry: ' . $e->getMessage());
                }
                if (time() >= $deadline) {
                    break 2;
                }
            }
        }
        s3_storage::cleanup(self::BATCH);
        mtrace("local_aireader: S3 migration moved {$moved} asset(s); {$failed} failure(s).");

        // Failures sit out their cooldown and are picked up by the scheduled
        // sync, so only hand over when this run stopped for lack of time.
        $remaining = array_diff_key(s3_storage::candidates(count($tried) + 1), $tried);
        if ($remaining) {
            self::queue(false);
        } else if (!s3_storage::count_candidates()) {
            set_config('s3_migration_finished', time(), 'local_aireader');
        }
    }
}
