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
 * Estimates the OpenAI spend of a generated narration asset.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\manager;

/**
 * Per-model, date-effective cost estimation for text-to-speech narration.
 *
 * Costs are estimated from the narration character count captured at
 * generation time multiplied by an admin-maintained USD-per-million-characters
 * rate. The rate schedule supports effective-from dates so that changing a
 * price only affects audio generated on or after the change: an asset is always
 * priced with the rate that was in effect when it was generated, keeping
 * historical cost records stable while new prices apply going forward.
 *
 * These are budgeting estimates, not an invoice: there is no OpenAI pricing
 * API to pull from, and translation and Whisper alignment are billed
 * separately and excluded here.
 *
 * @package local_aireader
 */
class cost_calculator {
    /** @var string|null Raw config the cached schedule was parsed from. */
    private static $cachedraw = null;

    /** @var array<string,array<int,array{from:int,rate:float}>>|null Parsed schedule cache. */
    private static $cachedschedule = null;

    /**
     * The built-in default pricing schedule, also used as the admin setting
     * default. One model per line: "model, rate" (USD per 1,000,000 chars).
     *
     * @return string
     */
    public static function default_pricing(): string {
        return "gpt-4o-mini-tts, 10.00\n"
            . "tts-1, 15.00\n"
            . "tts-1-hd, 30.00";
    }

    /**
     * Parse the configured pricing schedule into a per-model, date-sorted list.
     *
     * Each line is "model, rate[, YYYY-MM-DD]". The optional date is the
     * effective-from date. "*" may be used as the model for a catch-all rate.
     * Re-parses only when the underlying config string changes.
     *
     * @return array<string,array<int,array{from:int,rate:float}>>
     */
    public static function pricing_schedule(): array {
        $raw = (string)get_config('local_aireader', 'pricing');
        if (trim($raw) === '') {
            $raw = self::default_pricing();
        }
        if (self::$cachedschedule !== null && self::$cachedraw === $raw) {
            return self::$cachedschedule;
        }

        $schedule = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2 || $parts[0] === '' || !is_numeric($parts[1])) {
                continue;
            }
            $model = \core_text::strtolower($parts[0]);
            $rate = (float)$parts[1];
            $from = 0;
            if (!empty($parts[2])) {
                $ts = strtotime($parts[2]);
                if ($ts !== false) {
                    $from = $ts;
                }
            }
            $schedule[$model][] = ['from' => $from, 'rate' => $rate];
        }
        foreach ($schedule as &$entries) {
            usort($entries, static function (array $a, array $b): int {
                return $a['from'] <=> $b['from'];
            });
        }
        unset($entries);

        self::$cachedraw = $raw;
        self::$cachedschedule = $schedule;
        return $schedule;
    }

    /**
     * Resolve the per-million-character rate for a model at a point in time.
     *
     * @param string $model Model id (case-insensitive).
     * @param int|null $attime Unix time the asset was generated; defaults to now.
     * @return float|null USD per 1,000,000 characters, or null if no rate applies.
     */
    public static function rate_for_model(string $model, ?int $attime = null): ?float {
        $attime = $attime ?? time();
        $schedule = self::pricing_schedule();
        $key = \core_text::strtolower(trim($model));
        $entries = $schedule[$key] ?? $schedule['*'] ?? null;
        if (empty($entries)) {
            return null;
        }
        // Entries are sorted by effective date ascending. Use the latest one
        // whose date has already arrived; fall back to the earliest for assets
        // older than every dated entry.
        $rate = $entries[0]['rate'];
        foreach ($entries as $entry) {
            if ($entry['from'] <= $attime) {
                $rate = $entry['rate'];
            } else {
                break;
            }
        }
        return $rate;
    }

    /**
     * Estimate the USD cost of a single asset.
     *
     * @param string $model The TTS model id stored on the asset.
     * @param int|null $inputchars Narration character count, or null if unknown.
     * @param int|null $attime Unix time the asset was generated; defaults to now.
     * @return float|null Estimated USD, or null when it cannot be estimated (no
     *                    character count recorded, or no rate for the model).
     */
    public static function estimate_usd(string $model, ?int $inputchars, ?int $attime = null): ?float {
        if ($inputchars === null || $inputchars <= 0) {
            return null;
        }
        $rate = self::rate_for_model($model, $attime);
        if ($rate === null) {
            return null;
        }
        return $inputchars / 1000000 * $rate;
    }

    /**
     * Whether a rate (model-specific or wildcard) is configured for the model.
     *
     * @param string $model Model id (case-insensitive).
     * @return bool
     */
    public static function has_known_rate(string $model): bool {
        $schedule = self::pricing_schedule();
        $key = \core_text::strtolower(trim($model));
        return isset($schedule[$key]) || isset($schedule['*']);
    }

    /**
     * Format an estimated cost for display, e.g. "$0.4823" or a dash when unknown.
     *
     * @param float|null $usd Estimated USD, or null.
     * @return string
     */
    public static function format_usd(?float $usd): string {
        if ($usd === null) {
            return '—';
        }
        // Sub-cent figures are common per asset, so show four decimal places.
        return '$' . number_format($usd, 4);
    }
}
