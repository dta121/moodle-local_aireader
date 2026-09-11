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
 * Tests for clearing ad hoc task rows that have run out of attempts.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\task;

use local_aireader\manager\asset_manager;
use local_aireader\manager\storage;

/**
 * Tests for {@see dead_task_cleaner}.
 *
 * The safety properties matter more than the happy path: this deletes rows on a
 * production site, and the one thing it must never do is touch a learner's
 * audio or another component's tasks.
 *
 * @coversDefaultClass \local_aireader\task\dead_task_cleaner
 */
final class dead_task_cleaner_test extends \advanced_testcase {
    /**
     * Only the plugin's exhausted rows are reported. Healthy rows, rows with
     * unlimited attempts, and other components' rows are left alone.
     *
     * @covers ::find_dead
     */
    public function test_only_exhausted_plugin_rows_are_found(): void {
        $this->resetAfterTest();

        $dead = $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0);
        $this->insert_task('local_aireader\task\align_audio', 'local_aireader', 12);
        $this->insert_task('local_aireader\task\align_audio', 'local_aireader', null);
        $this->insert_task('mod_foo\task\other', 'mod_foo', 0);

        $found = dead_task_cleaner::find_dead();

        $this->assertCount(1, $found);
        $this->assertSame($dead, $found[0]->id);
        $this->assertSame('local_aireader\task\generate_audio', $found[0]->classname);
    }

    /**
     * A row that is executing right now is excluded. Zero-attempt rows can
     * still run, because "Run now" bypasses the attempts filter on purpose, and
     * deleting one mid-run would be a real race.
     *
     * @covers ::find_dead
     */
    public function test_a_running_row_is_never_touched(): void {
        global $DB;
        $this->resetAfterTest();

        $running = $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0);
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $running]);

        $this->assertSame([], dead_task_cleaner::find_dead());

        dead_task_cleaner::clear(false);
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $running]));
    }

    /**
     * A dry run reports exactly what it would delete and deletes nothing.
     *
     * @covers ::clear
     */
    public function test_dry_run_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        $dead = $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0);

        $reported = dead_task_cleaner::clear(true);

        $this->assertCount(1, $reported);
        $this->assertSame($dead, $reported[0]->id);
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $dead]));
    }

    /**
     * Clearing removes the exhausted rows, reports them, and is idempotent: a
     * second run finds nothing left to do.
     *
     * @covers ::clear
     */
    public function test_clear_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        $dead = $this->insert_task('local_aireader\task\align_audio', 'local_aireader', 0);
        $live = $this->insert_task('local_aireader\task\align_audio', 'local_aireader', 5);

        $first = dead_task_cleaner::clear(false);
        $this->assertCount(1, $first);
        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $dead]));
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $live]));

        $this->assertSame([], dead_task_cleaner::clear(false));
    }

    /**
     * The asset row and its stored mp3 survive. Deleting the task row is the
     * whole recovery; the work is re-queued from the asset, not the task.
     *
     * @covers ::clear
     */
    public function test_the_asset_and_its_audio_are_never_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assetid, $contextid] = $this->create_asset_with_audio();
        $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0, $assetid);

        $reported = dead_task_cleaner::clear(false);

        $this->assertSame($assetid, $reported[0]->assetid);
        $this->assertSame(asset_manager::STATUS_ERROR, $reported[0]->assetstatus);
        $this->assertTrue($DB->record_exists('local_aireader_asset', ['id' => $assetid]));
        $this->assertNotEmpty(get_file_storage()->get_area_files(
            $contextid, storage::COMPONENT, storage::FILEAREA, $assetid, 'itemid', false));
    }

    /**
     * A payload whose asset has already been deleted is still reported and
     * still cleared, rather than throwing on the missing row.
     *
     * @covers ::find_dead
     */
    public function test_orphaned_payload_is_reported_without_an_asset(): void {
        $this->resetAfterTest();

        $this->insert_task('local_aireader\task\generate_audio', 'local_aireader', 0, 999999);

        $found = dead_task_cleaner::find_dead();

        $this->assertSame(999999, $found[0]->assetid);
        $this->assertNull($found[0]->assetstatus);
    }

    /**
     * Insert a task_adhoc row directly, which is the only way to reach the
     * attempts values this class exists to handle.
     *
     * @param string $classname Fully qualified task class name.
     * @param string $component Owning component.
     * @param int|null $attempts Remaining attempts; null means unlimited.
     * @param int $assetid Asset id to put in the payload, 0 for no payload.
     * @return int The inserted row id.
     */
    private function insert_task(string $classname, string $component, ?int $attempts, int $assetid = 0): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('task_adhoc', (object)[
            'component'         => $component,
            'classname'         => $classname,
            'nextruntime'       => $now,
            'faildelay'         => 86400,
            'customdata'        => $assetid > 0 ? json_encode(['assetid' => $assetid]) : null,
            'timecreated'       => $now,
            'attemptsavailable' => $attempts,
            'firststartingtime' => $now - 3600,
        ]);
    }

    /**
     * Create a failed asset that still owns a stored mp3.
     *
     * @return array [asset id, context id].
     */
    private function create_asset_with_audio(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        $now = time();
        $assetid = (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid'      => (int)$course->id,
            'cmid'          => (int)$cm->id,
            'contextid'     => (int)$context->id,
            'module'        => 'page',
            'instanceid'    => (int)$cm->instance,
            'chapterid'     => 0,
            'lang'          => 'en',
            'voice'         => 'marin',
            'model'         => 'gpt-4o-mini-tts',
            'sourcehash'    => hash('sha256', 'cleaner test'),
            'status'        => asset_manager::STATUS_ERROR,
            'lasterror'     => 'Translation request failed',
            'bytesize'      => 16,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastrequested' => $now,
        ]);

        get_file_storage()->create_file_from_string([
            'contextid' => (int)$context->id,
            'component' => storage::COMPONENT,
            'filearea'  => storage::FILEAREA,
            'itemid'    => $assetid,
            'filepath'  => '/',
            'filename'  => "asset-{$assetid}.mp3",
            'mimetype'  => 'audio/mpeg',
        ], str_repeat('a', 16));

        return [$assetid, (int)$context->id];
    }
}
