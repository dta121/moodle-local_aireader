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
 * Tests for the translation cache and LRU garbage collection.
 *
 * @package    local_aireader
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Tests for {@see translation_manager}.
 *
 * @coversDefaultClass \local_aireader\manager\translation_manager
 */
final class translation_manager_test extends \advanced_testcase {
    /**
     * Variants of the same base language code count as the same language,
     * so we don't waste a translation pass on en → en_us.
     *
     * @covers ::is_same_language
     */
    public function test_is_same_language_variants(): void {
        $this->assertTrue(translation_manager::is_same_language('en', 'en_us'));
        $this->assertTrue(translation_manager::is_same_language('en_gb', 'en_us'));
        $this->assertTrue(translation_manager::is_same_language('zh_cn', 'zh_tw'));
        $this->assertFalse(translation_manager::is_same_language('en', 'es'));
        $this->assertFalse(translation_manager::is_same_language('pt', 'es'));
    }

    /**
     * Same-language requests should never invoke the translator callable;
     * they short-circuit to the source text.
     *
     * @covers ::get_or_translate
     */
    public function test_get_or_translate_passthrough_on_same_lang(): void {
        $calls = 0;
        $result = translation_manager::get_or_translate(
            'Hello world',
            'en',
            'en_us',
            'gpt-4o-mini',
            function () use (&$calls): string {
                $calls++;
                return 'should not be invoked';
            }
        );
        $this->assertSame('Hello world', $result);
        $this->assertSame(0, $calls);
    }

    /**
     * A stored translation is retrievable by hash + target lang + model.
     *
     * @covers ::store
     * @covers ::get
     * @covers ::compute_texthash
     */
    public function test_store_and_get_roundtrip(): void {
        $this->resetAfterTest();
        translation_manager::store('Hello', 'en', 'es', 'gpt-4o-mini', 'Hola');
        $hash = translation_manager::compute_texthash('Hello');
        $this->assertSame('Hola', translation_manager::get($hash, 'es', 'gpt-4o-mini'));
        $this->assertNull(translation_manager::get($hash, 'fr', 'gpt-4o-mini'));
        $this->assertNull(translation_manager::get($hash, 'es', 'gpt-3.5'));
    }

    /**
     * `get_or_translate` populates the cache on a miss and reads from it on
     * the second call, so the translator is invoked exactly once.
     *
     * @covers ::get_or_translate
     */
    public function test_get_or_translate_caches_first_call(): void {
        $this->resetAfterTest();
        $calls = 0;
        $translator = function () use (&$calls): string {
            $calls++;
            return 'Hola';
        };

        $first = translation_manager::get_or_translate('Hello', 'en', 'es', 'gpt-4o-mini', $translator);
        $second = translation_manager::get_or_translate('Hello', 'en', 'es', 'gpt-4o-mini', $translator);

        $this->assertSame('Hola', $first);
        $this->assertSame('Hola', $second);
        $this->assertSame(1, $calls);
    }

    /**
     * LRU GC: rows untouched past the cutoff are purged; freshly-used rows
     * are kept.
     *
     * @covers ::purge_unused_older_than
     */
    public function test_purge_unused_older_than(): void {
        global $DB;
        $this->resetAfterTest();

        translation_manager::store('old text', 'en', 'es', 'm1', 'old');
        translation_manager::store('fresh text', 'en', 'es', 'm1', 'fresh');

        $oldhash = translation_manager::compute_texthash('old text');
        $DB->set_field(
            'local_aireader_translation',
            'lastusedtime',
            time() - 30 * DAYSECS,
            ['texthash' => $oldhash]
        );

        $purged = translation_manager::purge_unused_older_than(7 * DAYSECS, 100);
        $this->assertSame(1, $purged);

        $this->assertNull(translation_manager::get($oldhash, 'es', 'm1'));
        $freshhash = translation_manager::compute_texthash('fresh text');
        $this->assertSame('fresh', translation_manager::get($freshhash, 'es', 'm1'));
    }
}
