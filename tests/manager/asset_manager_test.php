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
 * Tests for asset_manager helpers: enabled_languages parsing,
 * max_narration_chars defaulting, and the chapter-visibility gate.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see asset_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\asset_manager
 */
final class asset_manager_test extends \advanced_testcase {
    /**
     * Comma- and whitespace-separated lang codes are normalised; duplicates
     * are dropped; empty settings fall back to English.
     *
     * @covers ::enabled_languages
     */
    public function test_enabled_languages_parses_setting(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en, es,fr  ;pt_br', 'local_aireader');
        $this->assertSame(['en', 'es', 'fr', 'pt_br'], asset_manager::enabled_languages());

        set_config('enabled_languages', 'en, en, es, es', 'local_aireader');
        $this->assertSame(['en', 'es'], asset_manager::enabled_languages());

        set_config('enabled_languages', '', 'local_aireader');
        $this->assertSame(['en'], asset_manager::enabled_languages());
    }

    /**
     * The `enabled_languages_extra` escape hatch is merged with the checklist,
     * deduplicated against it, and normalised to Moodle's lowercase/underscore
     * code form.
     *
     * @covers ::enabled_languages
     */
    public function test_enabled_languages_merges_extra_codes(): void {
        $this->resetAfterTest();
        set_config('enabled_languages', 'en,es', 'local_aireader');
        set_config('enabled_languages_extra', 'so, PT-BR, es', 'local_aireader');
        $this->assertSame(['en', 'es', 'so', 'pt_br'], asset_manager::enabled_languages());

        set_config('enabled_languages_extra', '', 'local_aireader');
        $this->assertSame(['en', 'es'], asset_manager::enabled_languages());
    }

    /**
     * The enabled-voices list always starts with the default voice, merges the
     * checklist and extra ids, normalises case, and deduplicates.
     *
     * @covers ::enabled_voices
     * @covers ::default_voice
     */
    public function test_enabled_voices_includes_default_and_merges(): void {
        $this->resetAfterTest();

        set_config('voice', 'marin', 'local_aireader');
        set_config('enabled_voices', 'alloy,marin', 'local_aireader');
        set_config('enabled_voices_extra', ' Nova , alloy', 'local_aireader');
        $this->assertSame(['marin', 'alloy', 'nova'], asset_manager::enabled_voices());

        // Default voice is always available even when the checklist omits it.
        set_config('voice', 'cedar', 'local_aireader');
        set_config('enabled_voices', 'alloy', 'local_aireader');
        set_config('enabled_voices_extra', '', 'local_aireader');
        $this->assertSame(['cedar', 'alloy'], asset_manager::enabled_voices());

        // Nothing configured: just the default voice.
        unset_config('enabled_voices', 'local_aireader');
        unset_config('voice', 'local_aireader');
        $this->assertSame(['marin'], asset_manager::enabled_voices());
    }

    /**
     * `max_narration_chars` defaults when unset or non-positive; admin
     * overrides are honoured otherwise.
     *
     * @covers ::max_narration_chars
     */
    public function test_max_narration_chars_defaults_and_overrides(): void {
        $this->resetAfterTest();
        unset_config('max_narration_chars', 'local_aireader');
        $this->assertSame(asset_manager::DEFAULT_MAX_NARRATION_CHARS, asset_manager::max_narration_chars());

        set_config('max_narration_chars', 0, 'local_aireader');
        $this->assertSame(asset_manager::DEFAULT_MAX_NARRATION_CHARS, asset_manager::max_narration_chars());

        set_config('max_narration_chars', 12345, 'local_aireader');
        $this->assertSame(12345, asset_manager::max_narration_chars());
    }

    /**
     * Page/no-chapter assets must hash and store consistently whether the
     * caller passes null or 0 for chapterid.
     *
     * @covers ::compute_hash
     * @covers ::ensure_row
     * @covers ::find_current
     */
    public function test_page_assets_normalise_chapterid_to_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        $hashfromnull = asset_manager::compute_hash('page', (int)$cm->id, null, 'en', 'marin', 'm1', 'Text');
        $hashfromzero = asset_manager::compute_hash('page', (int)$cm->id, 0, 'en', 'marin', 'm1', 'Text');
        $this->assertSame($hashfromnull, $hashfromzero);

        [$first, $matchedfirst] = asset_manager::ensure_row([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$cm->instance,
            'chapterid' => null,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'm1',
            'sourcehash' => $hashfromnull,
        ]);
        [$second, $matchedsecond] = asset_manager::ensure_row([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'instanceid' => (int)$cm->instance,
            'chapterid' => 0,
            'lang' => 'en',
            'voice' => 'marin',
            'model' => 'm1',
            'sourcehash' => $hashfromzero,
        ]);

        $this->assertFalse($matchedfirst);
        $this->assertTrue($matchedsecond);
        $this->assertSame((int)$first->id, (int)$second->id);
        $this->assertSame(
            1,
            $DB->count_records('local_aireader_asset', ['cmid' => $cm->id, 'chapterid' => 0])
        );
    }

    /**
     * Asset-id entry points must not accept an asset row under an unrelated
     * module context.
     *
     * @covers ::assert_asset_visible
     */
    public function test_assert_asset_visible_rejects_context_mismatch(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $pageone = $gen->create_module('page', ['course' => $course->id]);
        $pagetwo = $gen->create_module('page', ['course' => $course->id]);
        $cmone = get_coursemodule_from_instance('page', $pageone->id, $course->id, false, MUST_EXIST);
        $cmtwo = get_coursemodule_from_instance('page', $pagetwo->id, $course->id, false, MUST_EXIST);
        $contextone = \context_module::instance((int)$cmone->id);
        $contexttwo = \context_module::instance((int)$cmtwo->id);
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cmone->id,
            'contextid' => (int)$contextone->id,
            'module' => 'page',
            'chapterid' => 0,
        ];

        $this->expectException(\invalid_parameter_exception::class);
        asset_manager::assert_asset_visible($asset, $contexttwo);
    }

    /**
     * Page assets must not carry a Book chapter id; a corrupt row should fail
     * closed instead of being treated as a valid Page asset.
     *
     * @covers ::assert_asset_visible
     */
    public function test_assert_asset_visible_rejects_page_chapterid(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $asset = (object)[
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'module' => 'page',
            'chapterid' => 123,
        ];

        $this->expectException(\invalid_parameter_exception::class);
        asset_manager::assert_asset_visible($asset, $context);
    }

    /**
     * Visible chapters always pass the gate; hidden chapters require the
     * `mod/book:viewhiddenchapters` capability on the cm context.
     *
     * @covers ::assert_chapter_visible
     */
    public function test_assert_chapter_visible_enforces_book_visibility(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $book = $bookgen->create_instance(['course' => $course->id]);
        $visiblechapter = $bookgen->create_chapter(['bookid' => $book->id]);
        $hiddenchapter = $bookgen->create_chapter(['bookid' => $book->id, 'hidden' => 1]);

        $cm = get_coursemodule_from_instance('book', $book->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');

        // Visible chapter: passes for student.
        $this->setUser($student);
        asset_manager::assert_chapter_visible($cm, (int)$visiblechapter->id, $context);
        $this->assertTrue(true);

        // Hidden chapter: passes for teacher (has viewhiddenchapters).
        $this->setUser($teacher);
        asset_manager::assert_chapter_visible($cm, (int)$hiddenchapter->id, $context);
        $this->assertTrue(true);

        // Hidden chapter: rejected for student.
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        asset_manager::assert_chapter_visible($cm, (int)$hiddenchapter->id, $context);
    }

    /**
     * A chapter id that doesn't belong to the supplied cm's book raises a
     * dml exception (MUST_EXIST). This protects against a learner passing
     * a chapter id from an unrelated book.
     *
     * @covers ::assert_chapter_visible
     */
    public function test_assert_chapter_visible_rejects_cross_book_chapter(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $bookgen = $gen->get_plugin_generator('mod_book');
        $bookone = $bookgen->create_instance(['course' => $course->id]);
        $booktwo = $bookgen->create_instance(['course' => $course->id]);
        $chapterintwo = $bookgen->create_chapter(['bookid' => $booktwo->id]);

        $cmone = get_coursemodule_from_instance('book', $bookone->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cmone->id);

        $this->setUser($gen->create_and_enrol($course, 'editingteacher'));

        $this->expectException(\dml_exception::class);
        asset_manager::assert_chapter_visible($cmone, (int)$chapterintwo->id, $context);
    }

    /**
     * Queueing reports whether a task row was actually created.
     *
     * Moodle refuses a duplicate payload without looking at how many attempts
     * the existing row has left, so a row that has already given up goes on
     * suppressing every later queue. Callers have to be able to see that.
     *
     * @covers ::queue_generation
     */
    public function test_queue_generation_reports_a_blocked_queue(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertTrue(asset_manager::queue_generation(4242));
        $this->assertFalse(asset_manager::queue_generation(4242));

        // The same suppression applies once the existing row is out of
        // attempts, which is the state that stranded assets for four weeks.
        $DB->set_field('task_adhoc', 'attemptsavailable', 0, ['component' => 'local_aireader']);
        $this->assertFalse(asset_manager::queue_generation(4242));

        // A different asset is unaffected.
        $this->assertTrue(asset_manager::queue_generation(4343));
    }

    /**
     * Each failure pushes the next automatic attempt further out.
     *
     * Deleting the exhausted task row is what unblocks re-queueing, but it also
     * removed the accidental spend cap that row provided, so the asset has to
     * carry one of its own.
     *
     * @covers ::update_status
     */
    public function test_each_error_extends_the_cooldown(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_PENDING);

        asset_manager::update_status($assetid, asset_manager::STATUS_ERROR, 'HTTP 400');
        $first = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(1, (int)$first->failcount);
        $this->assertEqualsWithDelta($first->timemodified + 3600, (int)$first->retryafter, 5);

        asset_manager::update_status($assetid, asset_manager::STATUS_ERROR, 'HTTP 400');
        $second = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(2, (int)$second->failcount);
        $this->assertEqualsWithDelta($second->timemodified + 7200, (int)$second->retryafter, 5);
    }

    /**
     * An automatic caller is refused while the cool-down runs; a human asking
     * for it explicitly is not. That is the whole point of the distinction ,
     * page views must not be able to spend money in a loop, and Regenerate must
     * always do something.
     *
     * @covers ::queue_generation
     */
    public function test_the_cooldown_blocks_automatic_queues_but_not_forced_ones(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_ERROR);
        $DB->set_field('local_aireader_asset', 'retryafter', time() + 600, ['id' => $assetid]);

        $this->assertFalse(asset_manager::queue_generation($assetid));
        $this->assertFalse($DB->record_exists('task_adhoc', ['component' => 'local_aireader']));

        $this->assertTrue(asset_manager::queue_generation($assetid, true));
        $this->assertTrue($DB->record_exists('task_adhoc', ['component' => 'local_aireader']));
    }

    /**
     * Once the cool-down has expired the automatic path works again.
     *
     * @covers ::queue_generation
     */
    public function test_an_expired_cooldown_allows_the_queue_again(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_ERROR);
        $DB->set_field('local_aireader_asset', 'retryafter', time() - 1, ['id' => $assetid]);

        $this->assertTrue(asset_manager::queue_generation($assetid));
    }

    /**
     * Success wipes the slate: new audio is new bytes, so nothing about the
     * previous failures should hold back work on it.
     *
     * @covers ::record_generated
     */
    public function test_a_successful_generation_clears_both_cooldowns(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_ERROR);
        $DB->update_record('local_aireader_asset', (object)[
            'id'              => $assetid,
            'failcount'       => 4,
            'retryafter'      => time() + 86400,
            'alignfailcount'  => 3,
            'alignretryafter' => time() + 86400,
        ]);

        asset_manager::record_generated($assetid, 0, 1024, null, 500);

        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(0, (int)$row->failcount);
        $this->assertNull($row->retryafter);
        $this->assertSame(0, (int)$row->alignfailcount);
        $this->assertNull($row->alignretryafter);
    }

    /**
     * An alignment failure is recorded without disturbing the narration: the
     * mp3 still plays, so the asset must stay ready, but the reason has to be
     * written somewhere that outlives cron's log retention.
     *
     * @covers ::record_alignment_failure
     */
    public function test_an_alignment_failure_is_recorded_without_changing_status(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_READY);

        asset_manager::record_alignment_failure($assetid, 'Whisper returned no segments.');

        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(asset_manager::STATUS_READY, $row->status);
        $this->assertSame('Whisper returned no segments.', $row->lasterror);
        $this->assertSame(1, (int)$row->alignfailcount);
        $this->assertGreaterThan(time(), (int)$row->alignretryafter);
        // The generation cool-down is a separate concern and must not move.
        $this->assertSame(0, (int)$row->failcount);
        $this->assertNull($row->retryafter);
    }

    /**
     * Alignment has its own cool-down, on its own field, so a failing
     * transcription cannot be re-attempted on every page view either.
     *
     * @covers ::queue_alignment
     * @covers ::clear_alignment_failure
     */
    public function test_the_alignment_cooldown_gates_alignment_only(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_READY);
        asset_manager::record_alignment_failure($assetid, 'Whisper returned no segments.');

        $this->assertFalse(asset_manager::queue_alignment($assetid));
        // Generation is untouched by the alignment cool-down.
        $this->assertTrue(asset_manager::queue_generation($assetid));

        asset_manager::clear_alignment_failure($assetid);
        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertSame(0, (int)$row->alignfailcount);
        $this->assertNull($row->alignretryafter);
        $this->assertTrue(asset_manager::queue_alignment($assetid));
    }

    /**
     * Lifting the cool-downs leaves the failure counts alone, so if the work
     * fails again the backoff resumes rather than restarting at an hour.
     *
     * @covers ::clear_retry_cooldowns
     */
    public function test_clearing_cooldowns_keeps_the_failure_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $assetid = $this->create_asset(asset_manager::STATUS_ERROR);
        $DB->update_record('local_aireader_asset', (object)[
            'id'              => $assetid,
            'failcount'       => 3,
            'retryafter'      => time() + 86400,
            'alignfailcount'  => 2,
            'alignretryafter' => time() + 86400,
        ]);

        $this->assertSame(1, asset_manager::clear_retry_cooldowns([$assetid, 0, $assetid]));

        $row = $DB->get_record('local_aireader_asset', ['id' => $assetid]);
        $this->assertNull($row->retryafter);
        $this->assertNull($row->alignretryafter);
        $this->assertSame(3, (int)$row->failcount);
        $this->assertSame(2, (int)$row->alignfailcount);
        $this->assertSame(0, asset_manager::clear_retry_cooldowns([]));
    }

    /**
     * Create a minimal asset row against a real page activity.
     *
     * @param string $status One of the STATUS_* constants.
     * @return int Asset id.
     */
    private function create_asset(string $status): int {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        $now = time();
        return (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid'      => (int)$course->id,
            'cmid'          => (int)$cm->id,
            'contextid'     => (int)$context->id,
            'module'        => 'page',
            'instanceid'    => (int)$cm->instance,
            'chapterid'     => 0,
            'lang'          => 'en',
            'voice'         => 'marin',
            'model'         => 'gpt-4o-mini-tts',
            'sourcehash'    => hash('sha256', 'backoff test ' . $status),
            'status'        => $status,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastrequested' => $now,
        ]);
    }
}
