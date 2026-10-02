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
 * Synchronize generated audio with the configured S3 destination.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\manager\s3_storage;
use local_aireader\manager\asset_manager;

/**
 * Retry transfers independently from paid narration generation.
 *
 * @package local_aireader
 */
class sync_s3 extends \core\task\scheduled_task {
    /**
     * Name shown in scheduled tasks.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_s3', 'local_aireader');
    }

    /**
     * Transfer and clean up a bounded batch.
     */
    public function execute(): void {
        // Source deletion may have raced a long generation or transfer holding its lock.
        asset_manager::purge_orphaned();
        // Cleanup still runs after uploads are disabled or their destination changes.
        s3_storage::cleanup();
        foreach (s3_storage::candidates() as $asset) {
            try {
                if (s3_storage::sync_asset((int)$asset->id)) {
                    mtrace('local_aireader: S3 storage synchronized for asset ' . $asset->id);
                }
            } catch (\moodle_exception $e) {
                mtrace('local_aireader: S3 transfer for asset ' . $asset->id . ' will retry: ' . $e->getMessage());
            }
        }
    }
}
