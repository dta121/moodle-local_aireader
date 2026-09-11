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
 * Tests for the narration report's error column.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\output;

use moodle_url;

/**
 * Tests for {@see log_table}.
 *
 * Narrow on purpose: the one behaviour worth pinning is that a failure recorded
 * against an asset actually reaches a screen. An alignment failure leaves the
 * asset `ready`, the mp3 plays, only the karaoke highlighting is missing, and
 * the column used to be gated on `status === 'error'`, so that failure was
 * rendered nowhere at all.
 *
 * @coversDefaultClass \local_aireader\output\log_table
 */
final class log_table_test extends \advanced_testcase {
    /**
     * A failure on a ready asset is shown, not swallowed.
     *
     * @covers ::col_lasterror
     */
    public function test_an_error_on_a_ready_asset_is_rendered(): void {
        $this->resetAfterTest();

        $rendered = $this->render_lasterror((object)[
            'status'    => 'ready',
            'lasterror' => 'Whisper returned no segments.',
        ]);

        $this->assertStringContainsString('Whisper returned no segments.', $rendered);
    }

    /**
     * An errored asset still shows its reason, as it always did.
     *
     * @covers ::col_lasterror
     */
    public function test_an_errored_asset_still_shows_its_reason(): void {
        $this->resetAfterTest();

        $rendered = $this->render_lasterror((object)[
            'status'    => 'error',
            'lasterror' => 'HTTP 400: model not available',
        ]);

        $this->assertStringContainsString('model not available', $rendered);
    }

    /**
     * A healthy row shows a dash rather than an empty cell.
     *
     * @covers ::col_lasterror
     */
    public function test_a_row_with_no_error_shows_a_dash(): void {
        $this->resetAfterTest();

        $this->assertSame('—', $this->render_lasterror((object)[
            'status'    => 'ready',
            'lasterror' => null,
        ]));
        $this->assertSame('—', $this->render_lasterror((object)[
            'status'    => 'error',
            'lasterror' => '',
        ]));
    }

    /**
     * Render the error cell for one row.
     *
     * @param \stdClass $row Partial asset row: status and lasterror.
     * @return string
     */
    private function render_lasterror(\stdClass $row): string {
        $table = new log_table('local_aireader_test', '', new moodle_url('/local/aireader/report.php'));
        return $table->col_lasterror($row);
    }
}
