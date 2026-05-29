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
 * Per-model cost estimation for text-to-speech narration.
 *
 * Costs are estimated from the narration character count captured at
 * generation time. The published OpenAI pricing differs by billing unit
 * (characters for the tts-1 family, tokens/minutes for gpt-4o-mini-tts); we
 * normalise everything to a USD-per-million-characters rate so a single stored
 * quantity (inputchars) drives the estimate for every model. These are
 * estimates for budgeting, not an invoice — translation and Whisper alignment,
 * billed separately, are not included.
 *
 * @package local_aireader
 */
class cost_calculator {
    /**
     * @var array<string,float> USD per 1,000,000 narration characters, keyed by
     * lower-cased model id. tts-1 / tts-1-hd are OpenAI's published per-character
     * rates; gpt-4o-mini-tts is a blended per-character estimate consistent with
     * the plugin's own ~$0.50-per-50,000-characters guidance.
     */
    private const RATE_PER_MILLION_CHARS = [
        'gpt-4o-mini-tts' => 10.00,
        'tts-1'           => 15.00,
        'tts-1-hd'        => 30.00,
    ];

    /** @var float Fallback per-million-character rate for unrecognised models. */
    private const DEFAULT_RATE_PER_MILLION_CHARS = 15.00;

    /**
     * Estimate the USD cost of a single asset from its model and character count.
     *
     * @param string $model The TTS model id stored on the asset.
     * @param int|null $inputchars Narration character count, or null if unknown.
     * @return float|null Estimated USD, or null when the cost cannot be estimated
     *                    (no character count recorded, e.g. a historical row).
     */
    public static function estimate_usd(string $model, ?int $inputchars): ?float {
        if ($inputchars === null || $inputchars <= 0) {
            return null;
        }
        return $inputchars / 1000000 * self::rate_for_model($model);
    }

    /**
     * Resolve the per-million-character rate for a model, falling back when the
     * model is not in the table.
     *
     * @param string $model Model id (case-insensitive).
     * @return float USD per 1,000,000 characters.
     */
    public static function rate_for_model(string $model): float {
        $key = \core_text::strtolower(trim($model));
        return self::RATE_PER_MILLION_CHARS[$key] ?? self::DEFAULT_RATE_PER_MILLION_CHARS;
    }

    /**
     * Whether the model id is one we have a published rate for (as opposed to
     * the generic fallback).
     *
     * @param string $model Model id (case-insensitive).
     * @return bool
     */
    public static function has_known_rate(string $model): bool {
        return isset(self::RATE_PER_MILLION_CHARS[\core_text::strtolower(trim($model))]);
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
