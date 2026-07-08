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
 * Tests for dashboard_metrics: the aggregate queries behind the usage dashboard.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see dashboard_metrics}.
 *
 * @coversDefaultClass \local_aireader\manager\dashboard_metrics
 */
final class dashboard_metrics_test extends \advanced_testcase {
    /** @var \stdClass A course to attach seeded assets to. */
    private $course;

    /**
     * Common fixture: a reset DB and one course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Insert an asset row directly, returning its id.
     *
     * @param string $status Asset status.
     * @param string $lang Language code.
     * @param int $cmid Course module id (arbitrary for counting).
     * @param int $bytes File size in bytes.
     * @param int|null $duration Duration in seconds, or null.
     * @param int|null $generated lastgenerated timestamp, or null.
     * @return int New asset id.
     */
    private function make_asset(
        string $status,
        string $lang = 'en',
        int $cmid = 1,
        int $bytes = 0,
        ?int $duration = null,
        ?int $generated = null
    ): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_aireader_asset', (object)[
            'courseid'      => (int)$this->course->id,
            'cmid'          => $cmid,
            'contextid'     => 1,
            'module'        => 'page',
            'instanceid'    => $cmid,
            'chapterid'     => 0,
            'lang'          => $lang,
            'voice'         => 'marin',
            'model'         => 'gpt-4o-mini-tts',
            'sourcehash'    => hash('sha256', "{$cmid}|{$lang}|{$status}|{$bytes}"),
            'status'        => $status,
            'bytesize'      => $bytes,
            'durationsecs'  => $duration,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'lastgenerated' => $generated,
        ]);
    }

    /**
     * status_breakdown returns every status with correct counts.
     *
     * @covers ::status_breakdown
     */
    public function test_status_breakdown(): void {
        $this->make_asset(asset_manager::STATUS_READY);
        $this->make_asset(asset_manager::STATUS_READY);
        $this->make_asset(asset_manager::STATUS_ERROR);
        $this->make_asset(asset_manager::STATUS_STALE);

        $breakdown = dashboard_metrics::status_breakdown();
        $this->assertSame(2, $breakdown[asset_manager::STATUS_READY]);
        $this->assertSame(1, $breakdown[asset_manager::STATUS_ERROR]);
        $this->assertSame(1, $breakdown[asset_manager::STATUS_STALE]);
        $this->assertSame(0, $breakdown[asset_manager::STATUS_PENDING]);
    }

    /**
     * Reach counts distinct learners with a position on a ready asset only.
     *
     * @covers ::reach
     */
    public function test_reach_counts_distinct_listeners_on_ready_assets(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $user1 = $gen->create_user();
        $user2 = $gen->create_user();
        $ready = $this->make_asset(asset_manager::STATUS_READY);
        $pending = $this->make_asset(asset_manager::STATUS_PENDING);

        foreach ([[$user1->id, $ready], [$user2->id, $ready], [$user1->id, $pending]] as [$uid, $aid]) {
            $DB->insert_record('local_aireader_position', (object)[
                'userid'       => $uid,
                'assetid'      => $aid,
                'position'     => 30,
                'timemodified' => time(),
            ]);
        }

        // user1 + user2 on the ready asset = 2; the position on the pending asset is ignored.
        $this->assertSame(2, dashboard_metrics::reach());
    }

    /**
     * activities_narrated and storage_bytes only count ready assets.
     *
     * @covers ::activities_narrated
     * @covers ::storage_bytes
     */
    public function test_activities_and_storage(): void {
        $this->make_asset(asset_manager::STATUS_READY, 'en', 10, 1000);
        $this->make_asset(asset_manager::STATUS_READY, 'es', 10, 2000);
        $this->make_asset(asset_manager::STATUS_READY, 'en', 20, 500);
        $this->make_asset(asset_manager::STATUS_PENDING, 'en', 30, 9999);

        // Distinct ready cmids: 10 and 20.
        $this->assertSame(2, dashboard_metrics::activities_narrated());
        // Ready bytes: 1000 + 2000 + 500 (pending excluded).
        $this->assertSame(3500, dashboard_metrics::storage_bytes());
    }

    /**
     * language_demand counts ready assets per language, descending.
     *
     * @covers ::language_demand
     */
    public function test_language_demand(): void {
        $this->make_asset(asset_manager::STATUS_READY, 'en');
        $this->make_asset(asset_manager::STATUS_READY, 'en');
        $this->make_asset(asset_manager::STATUS_READY, 'es');
        $this->make_asset(asset_manager::STATUS_PENDING, 'fr');

        $demand = dashboard_metrics::language_demand();
        $this->assertSame(['en' => 2, 'es' => 1], $demand);
        // Highest demand first.
        $this->assertSame('en', array_key_first($demand));
    }

    /**
     * audio_minutes sums stored duration and falls back to the last aligned
     * segment end time when duration is missing.
     *
     * @covers ::audio_minutes
     */
    public function test_audio_minutes_with_segment_fallback(): void {
        global $DB;
        $this->make_asset(asset_manager::STATUS_READY, 'en', 1, 0, 120);
        $noduration = $this->make_asset(asset_manager::STATUS_READY, 'en', 2, 0, null);
        $this->make_asset(asset_manager::STATUS_READY, 'en', 3, 0, null);

        // 3 minutes' worth of aligned audio for the no-duration asset.
        $DB->insert_record('local_aireader_segment', (object)[
            'assetid' => $noduration,
            'idx'     => 0,
            'startms' => 0,
            'endms'   => 180000,
            'segtext' => 'x',
        ]);

        // 120s + 180s + 0s = 300s = 5.0 minutes.
        $this->assertSame(5.0, dashboard_metrics::audio_minutes());
    }

    /**
     * adoption_over_time buckets ready assets by calendar month, sorted.
     *
     * @covers ::adoption_over_time
     */
    public function test_adoption_over_time(): void {
        $jan = make_timestamp(2026, 1, 15);
        $feb = make_timestamp(2026, 2, 10);
        $this->make_asset(asset_manager::STATUS_READY, 'en', 1, 0, null, $feb);
        $this->make_asset(asset_manager::STATUS_READY, 'en', 2, 0, null, $jan);
        $this->make_asset(asset_manager::STATUS_READY, 'en', 3, 0, null, $jan);

        $adoption = dashboard_metrics::adoption_over_time();
        $this->assertSame(['2026-01' => 2, '2026-02' => 1], $adoption);
    }

    /**
     * instructor_optouts counts disabled override rows only.
     *
     * @covers ::instructor_optouts
     */
    public function test_instructor_optouts(): void {
        override_manager::set((int)$this->course->id, 100, 0, false);
        override_manager::set((int)$this->course->id, 101, 0, true);
        override_manager::set((int)$this->course->id, 102, 5, false);

        $this->assertSame(2, dashboard_metrics::instructor_optouts());
    }

    /**
     * failure_percent is error/(ready+error); null when no terminal assets.
     *
     * @covers ::failure_percent
     */
    public function test_failure_percent(): void {
        $this->assertNull(dashboard_metrics::failure_percent());

        $this->make_asset(asset_manager::STATUS_READY);
        $this->make_asset(asset_manager::STATUS_READY);
        $this->make_asset(asset_manager::STATUS_READY);
        $this->make_asset(asset_manager::STATUS_ERROR);

        // 1 error of 4 terminal = 25%.
        $this->assertSame(25.0, dashboard_metrics::failure_percent());
    }

    /**
     * site_summary composes the headline figures.
     *
     * @covers ::site_summary
     */
    public function test_site_summary(): void {
        $this->make_asset(asset_manager::STATUS_READY, 'en', 1, 1024, 60);
        $this->make_asset(asset_manager::STATUS_ERROR, 'en', 2);

        $summary = dashboard_metrics::site_summary();
        $this->assertSame(2, $summary->totalassets);
        $this->assertSame(1, $summary->readyassets);
        $this->assertSame(1024, $summary->storagebytes);
        $this->assertSame(1.0, $summary->audiominutes);
        $this->assertSame(50.0, $summary->failurepercent);
    }
}
