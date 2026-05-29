# Changelog

All notable changes to `local_aireader` are documented in this file.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/);
versions follow [Semantic Versioning](https://semver.org/).

## [1.0.4] — 2026-05-29

### Fixed

- **TTS failed with *Input of N tokens is over the maximum input limit of 2000
  tokens*** on `gpt-4o-mini-tts`, especially for translated (CJK) narration.
  `chunk_text()` split purely on character count (3800), which suits the
  `tts-1` family's 4096-**character** limit but ignores the newer model's
  2000-**token** cap — and 3800 dense CJK characters are ~3000 tokens. Chunking
  is now token-aware (`estimate_tokens()` + a 1800-token default ceiling applied
  alongside the character cap), and sentence splitting now recognises CJK
  terminators (`。．！？`) which carry no trailing whitespace, so unspaced
  Japanese/Chinese narration is split at real sentence boundaries instead of
  falling through to a single oversized hard cut. Added unit tests for the
  token cap and the estimator.

## [1.0.3] — 2026-05-29

### Fixed

- **`generate_audio` still fatalled after 1.0.2** — *Call to undefined function
  file_rewrite_pluginfile_urls()*. That function is defined in
  `lib/filelib.php`, which Moodle does **not** auto-load in the cron / adhoc-task
  context the extractor runs in, so the global was genuinely undefined there
  (the 1.0.2 namespace qualification only changed the error text, not the
  outcome). `content_extractor::extract()` now `require_once`s
  `$CFG->libdir/filelib.php` before use. The namespace qualifications from 1.0.2
  are retained.

## [1.0.2] — 2026-05-29

### Fixed

- **Fatal in the `generate_audio` ad hoc task.** Six global Moodle functions in
  `content_extractor` (`file_rewrite_pluginfile_urls`,
  `get_coursemodule_from_id`, `format_string`, `html_to_text`, `get_string`,
  `get_string_manager`) were called unqualified from inside the
  `local_aireader\manager` namespace, so PHP resolved them as undefined
  namespaced functions and every generation failed with *Call to undefined
  function local_aireader\manager\file_rewrite_pluginfile_urls()*. All are now
  fully qualified with a leading `\`.

### Added

- **Backup / Restore API support.** New module-level `backup_local_plugin` /
  `restore_local_plugin` classes (`backup/moodle2/`) carry the per-resource
  narration overrides (`local_aireader_override`, both activity-level and
  per-chapter) with a backed-up `mod_page` / `mod_book` activity. Restore is
  deferred to `after_restore_module()` so `book_chapter` id mappings exist
  before chapter-level overrides are remapped; stale chapter overrides are
  skipped, `usermodified` is remapped when users are included, and existing
  `(cmid, chapterid)` rows are not duplicated.

### Packaging

- Added the standard Moodle GPL boilerplate header to
  `templates/manager_offline.mustache`, `templates/player.mustache`, and
  `amd/src/player.js` (the latter also gains a proper
  `@module`/`@copyright`/`@license` docblock) for plugin-directory submission.

## [1.0.1] — 2026-05-28

### Fixed

- Upgrade step `2026052000` now drops the dependent indexes before altering
  `chapterid`, fixing a failed upgrade on sites with the older schema.
- Tightened `mod_book` chapter validation and TTS chunk sizing.
- Hardened asset-visibility checks and normalized `mod_page` asset keys.
- Stabilized a flaky hidden-chapter test by pinning pagenums in the generator
  helper.

### Added

- Prep for the Moodle plugin directory: `LICENSE` file, full UI
  internationalization, expanded docs, and player/transcript screenshots in the
  README.

## [1.0.0] — 2026-05-19

First stable release. Plugin is now `MATURITY_STABLE`.

### Security

Eight findings from a v0.7.4 security audit landed in this release.

- **Hidden book chapters are now gated.** `get_status`, `request_regen`, and
  `local_aireader_pluginfile` reject chapters whose `book_chapters.hidden`
  flag is set unless the caller has `mod/book:viewhiddenchapters`. Previously
  a learner with module-level access could narrate any chapter id.
- **Language parameter is server-validated.** `lang` on every external
  function must be in the admin `enabled_languages` allowlist. Closes an
  OpenAI cost-amplification DoS (looping ISO codes to queue translation+TTS).
- **Pluginfile uses course/cm-scoped `require_login`** so enrolment, course
  visibility, and activity availability rules apply alongside the capability
  check.
- **Per-asset content cap.** New `max_narration_chars` admin setting
  (default 50000) refuses runaway pages before OpenAI is called.
- **Outbound HTTP is HTTPS-only.** New `http_guard` helper blocks `http://`,
  loopback, RFC1918 private addresses, link-local, and the cloud
  instance-metadata IP. Curl is locked to `CURLPROTO_HTTPS`.
- **OpenAI error bodies are sanitized** before being persisted to
  `asset.lasterror` or emitted to cron logs — Bearer tokens, `sk-…` keys,
  and long opaque identifiers are redacted.
- **External return types narrowed.** `text` segment values are now
  `PARAM_NOTAGS`, status messages are `PARAM_TEXT` (previously `PARAM_RAW`).
- **Removed unused `local/aireader:purge` capability** — declared but never
  enforced, so users assigning a custom role couldn't tell it did nothing.

### Added

- **PHPUnit test suite** — 31 tests across 5 files covering the URL guard,
  error sanitizer, override resolution chain, translation cache LRU,
  asset_manager (enabled_languages parsing, max_narration_chars defaults,
  chapter visibility helper), and the get_status security gates.

### CI

- Matrix expanded from Moodle 4.5 only to **Moodle 4.5 LTS / 5.0 / 5.1 / 5.2**
  on **PHP 8.1 / 8.2 / 8.3 / 8.4** across both **PostgreSQL 16** and
  **MariaDB 10.11**.
- `actions/checkout` bumped to v5 (Node.js 24) ahead of the 2026-06-02
  GitHub Actions deprecation deadline.
- `Grunt` step no longer has `continue-on-error`; CSS regressions and
  AMD-build drift now fail loudly.
- Tracked `amd/build/*.map` in git so `moodle-plugin-ci grunt` sees a clean
  tree.
- Cleared every stylelint warning on `styles.css`.

### Notes

- No schema change. The upgrade savepoint at `2026051900` registers the
  `max_narration_chars` admin default; nothing else moves.
- `\local_aireader\manager\asset_manager::assert_chapter_visible()` accepts
  either `cm_info` or `stdClass` — fix landed alongside the tests after
  they caught the original `\stdClass`-only signature.

## [0.7.4] — 2026-05-15

### Fixed

- **Nav, menu, and player disappearing** on some pages. v0.7.2's broader
  in-place wrap fallbacks let the TreeWalker descend into Moodle's
  activity-chrome widgets (activity-header, completion-info, badges) —
  wrapping their text broke the JS that manages them, which cascaded.
  `WRAP_REJECT_SELECTOR` now explicitly rejects those wrappers, and
  `findWrapContainer` only returns narrow body selectors.

## [0.7.3] — 2026-05-15

### Changed

- `<mark>` spans are now invisible at rest. Subtle hover tint, full
  highlight only while the audio is narrating that segment.

## [0.7.2] — 2026-05-15

### Changed

- Lowered the in-place match threshold from 90% to 50% and stopped rolling
  back successful marks when the ratio falls below threshold — partial
  in-place highlighting is still useful alongside the transcript pane.
- Broadened wrap container fallback selectors with diagnostics.

## [0.7.1] — 2026-05-15

### Fixed

- Hotfix: tightened in-place wrap scope so navigation and breadcrumbs are
  never touched by the segment-matching pass.

## [0.7.0] — 2026-05-15

### Added

- **Karaoke-style highlighting.** New `align_audio` ad hoc task chains
  Whisper transcription after every TTS run; sentence-level segments are
  cached in `local_aireader_segment` and served via a new
  `local_aireader_get_transcript` web service.
- **Transcript pane** in the player with click-to-seek and auto-scroll.
- **In-place DOM `<mark>` wrapping** (admin toggle `highlight_in_place`)
  for narrations that match the source page text.

## [0.6.0] — 2026-05-14

### Added

- **Resume where you left off.** Per-user, per-asset playback position
  persisted via a new `local_aireader_set_position` web service.
- **Full GDPR privacy provider.** Implements metadata, plugin, and
  core_userlist providers; supports export, delete-by-user,
  delete-for-context, and delete-by-userlist for both per-user tables.

## [0.5.0] — 2026-05-13

### Added

- **Player UX sprint.** Scrub bar with click-and-drag seeking, skip ±15
  seconds, playback speed select (0.75× → 2×) persisted per-browser,
  MediaSession API integration for lock-screen and Bluetooth controls,
  keyboard shortcuts (k/j/l, arrows), and a "~N min listen" pre-play
  duration estimate.

## [0.4.1] — 2026-05-12

### Added

- LRU GC for the translation cache. `lastusedtime` is refreshed on every
  cache hit; the nightly scheduled task purges rows untouched past the
  retention window.

## [0.4.0] — 2026-05-11

### Added

- **Multi-language narration.** New `enabled_languages` admin allowlist
  surfaces a language picker on the player. Translation via OpenAI
  chat-completions (`gpt-4o-mini` by default), cached in
  `local_aireader_translation` keyed on `sha256(cleantext) + targetlang +
  model` so one translation pass serves every voice and TTS model.
- Variants of the same base language (e.g. `en_us`/`en_gb`) share a row.
- Optional eager pre-generation on save.

## [0.3.0] — 2026-05-08

### Changed

- Player redesigned to match the saylor.org article reader: orange circular
  play button, podcast-style scrub bar, time display, dedicated offline
  placeholder for managers.

## [0.2.0] — 2026-05-06

### Added

- **Per-resource enable/disable overrides.** Instructors can turn narration
  off for a specific page, a specific book, or a specific chapter from
  either the mod-form or the player itself.
- **Stale-asset garbage collection.** Nightly scheduled task purges stale
  rows older than `stale_retention_days`.
- Improved HTML extraction with inline cues for embedded video/audio/iframes
  so narration flows past mixed-media activities.

## [0.1.0] — 2026-05-04

Initial pre-release.

### Added

- Cache-first MP3 generation via OpenAI TTS for `mod_page` and `mod_book`.
- Background generation via Moodle ad hoc tasks.
- File storage through the Moodle File API (`local_aireader/audio` filearea).
- Capability-gated playback (`local/aireader:listen`,
  `local/aireader:manage`).
- AMD-driven player UI with status polling.
