# Changelog

All notable changes to `local_aireader` are documented in this file.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/);
versions follow [Semantic Versioning](https://semver.org/).

## [1.8.0] — 2026-07-22

### Added

- **Three compact player designs** (design source: claude.ai/design project
  "AI Reader settings redesign", AI Reader Player):
  - **Slim bar** (`slimbar`) — one 56px row instead of the full player's
    three: play button, "Listen to this content", and an idle subtitle that
    folds the estimated length and the mandatory AI disclosure into
    "2 min · AI-generated voice". Speed and transcript pills plus a slim
    bottom progress strip appear only after the learner presses play;
    skip ±15s, restart, download, regenerate, the manager on/off toggle, and
    the full disclosure text live in a ⋯ overflow menu.
  - **Slim pill** (`slimpill`) — near-zero idle footprint: a "Listen" pill
    that swaps itself for the slim bar on click (honours autoplay-on-expand).
  - **Pill + docked mini-player** (`dockpill`) — the pill becomes a
    "Now playing · follows you as you read" chip while the slim bar docks to
    the bottom of the viewport, so controls stay reachable on long pages and
    while following karaoke highlighting. Reserves body padding so the fixed
    bar never covers content, keeps safe-area insets on mobile, and opens the
    transcript pane upward above the bar.

  All three are variants of one new `player_slim` template that exposes the
  same hooks as the full player, so playback, resume, transcript, karaoke,
  language/voice switching, and completion tracking behave identically. The
  existing designs (full, banner, pill, accordion, inline) are unchanged.

## [1.7.0] — 2026-07-22

### Changed

- **Redesigned admin settings page.** The plugin settings
  (`Site administration → Plugins → Local plugins → AI Reader`) are now
  organised into six section cards — Setup & connection, Content & generation,
  Player, Cost tracking, Languages & translation, Transcript & highlighting —
  with a sticky sidebar (live filter + section navigation with scrollspy),
  per-section collapsed "advanced settings", status chips (plugin state, API
  key, learner languages, karaoke, modified count), toggle switches for
  checkboxes, per-setting "Modified" badges with default values and one-click
  reset, chip lists with an expandable grid for the language/voice checklists,
  an API-key "Configured" pill, and a sticky save bar with a live
  differs-from-default count and a Discard button. Implemented as progressive
  enhancement (new `local_aireader/admin_settings` AMD module + page-scoped
  CSS) over the native admin form, which remains the source of truth for
  values, validation, and saving — if the enhancement cannot run, the stock
  page renders unchanged. `settings.php` was reordered into the same six
  sections. Design source: claude.ai/design project "AI Reader settings
  redesign".

## [1.6.0] — 2026-07-22

### Added

- **Learner voice picker.** Admins can tick which OpenAI TTS voices learners
  may use ("Voices offered to learners" checklist, mirroring the language
  checklist, plus an "Additional voice ids" escape hatch for voices OpenAI
  ships before the built-in list is updated). When two or more voices are
  enabled, the player shows a voice picker next to the language picker;
  switching voices loads (or lazily generates) that voice's narration. The
  existing "Voice" setting becomes the default voice, is always offered, and
  remains the only voice used for teacher-save/eager pre-generation — extra
  voices are generated on first learner request only. The webservices gain an
  optional `voice` parameter with a server-side allowlist (each language ×
  voice pair is a separately billed generation, so learners cannot request
  arbitrary voices). No schema change: assets were already keyed by voice.
  Course ZIP downloads include enabled non-default-voice files with a
  "(lang, Voice)" filename marker. Master voice list lives in
  `openai_client::supported_voices()` (last synced with OpenAI docs July
  2026); note that tts-1 / tts-1-hd only accept the classic six voices.

## [1.5.0] — 2026-07-22

### Changed

- **"Languages offered to learners" is now a checklist instead of a free-text
  code field.** The admin setting lists every language OpenAI's speech models
  officially support (plus common regional variants like Portuguese (Brazil)
  and Chinese Simplified/Traditional), alphabetised by name — tick what you
  want to offer instead of typing Moodle language codes. The stored format is
  unchanged (comma-separated codes), so existing configurations migrate
  automatically with the previously enabled languages pre-ticked. The list
  lives in `openai_translator::supported_languages()`, which is also the
  source for player language-picker display names.

- **Translation defaults refreshed: gpt-5-mini and a stricter system prompt.**
  The default translation model is now `gpt-5-mini` and the default
  translation system prompt is a fuller professional-translator brief
  (natural target-language phrasing, established academic terminology,
  preserved structure/HTML/code/placeholders, output-only-the-translation).
  An upgrade step migrates sites still holding the old defaults verbatim;
  admin-customised values are left untouched. The request payload is now
  model-aware: GPT-5/o-series reasoning models no longer receive the
  `temperature` parameter they reject, and gpt-5-family models are asked for
  `minimal` reasoning effort so translation stays fast and cheap. Note:
  cached translations are keyed by model, so previously translated content
  re-translates once on first request under the new model.

### Added

- **`enabled_languages_extra` setting** — a small free-text escape hatch for
  language codes not on the checklist. OpenAI publishes no API to enumerate
  supported languages, so when they add one before the plugin's list is
  updated (or for unlisted regional locales), admins can enable it here
  immediately; codes are merged with the checklist and normalised
  (e.g. `PT-BR` → `pt_br`).

## [1.4.3] — 2026-07-22

### Security

- **Narration enable state is now enforced server-side, not just in the UI.**
  Previously the plugin master switch, the per-module switches
  (`enable_page` / `enable_book`), and per-activity/chapter overrides were only
  checked when deciding whether to inject the player. The web services and the
  pluginfile handler trusted the client: any user with `local/aireader:listen`
  could call the AJAX endpoints directly on a disabled scope to queue paid
  OpenAI TTS/translation generation (`get_status`), stream stored audio
  (pluginfile), read transcripts (`get_transcript`), and record listen progress
  that could complete the activity (`set_progress`). All of these now assert
  narration availability with the same gate order as the player-injection hook:
  master and per-module switches block everyone; a per-scope override blocks
  users without `local/aireader:manage` (managers keep access so they can
  verify narration before re-enabling it). New helper
  `asset_manager::is_narration_available()` / `assert_narration_available()`,
  new `error_narration_disabled` lang string, and regression tests.
- **Hardening:** the Whisper aligner now strips any path components from the
  staged upload filename (`clean_param(..., PARAM_FILE)`). The filename is
  plugin-generated today, so this was not exploitable — defense in depth only.

## [1.4.2] — 2026-07-08

### Added

- **Admin toggle for clickable in-page highlights** (`highlight_interactive`,
  default on). When on, learners can click a highlighted sentence in the page
  body to jump the audio there (unchanged behaviour). When off, the in-page
  highlighting is **follow-along only** — no pointer, no hover tint, no
  click-to-seek in the body — and click-to-seek remains available in the
  transcript pane. Lets sites keep the read-along highlight without turning body
  text into clickable controls. Only applies when in-page highlighting
  (`highlight_in_place`) is on. JS/CSS change; the version bump rolls
  jsrev/themerev and registers the new default.

## [1.4.1] — 2026-07-08

### Fixed

- **In-page highlighting no longer appears until the learner starts the reader.**
  Previously, whenever an activity's audio was already cached, the player
  injected the karaoke `<mark>` spans into the page body on load — before the
  learner pressed Listen — so random spans of body text became hover-tinted and
  click-to-seek. With partial match coverage this looked arbitrary and could
  start playback unexpectedly. The in-place marks are now injected only once the
  learner engages the reader:
  - Pressing play injects the marks and begins follow-along highlighting.
  - A learner **returning to a saved resume position** counts as already engaged:
    the marks are restored and the sentence where they left off is highlighted
    and scrolled into view, so returning picks up visually and audibly where
    they stopped.
  - A learner who never starts the reader sees a completely unmodified page — no
    marks, no hover cursor, no click-to-seek in the body.

  The transcript pane (opened explicitly by the learner) is unchanged. JS-only
  change; the version bump rolls jsrev so browsers fetch the rebuilt bundle.

## [1.4.0] — 2026-07-08

### Added

- **Admin usage dashboard.** A new page under *Site administration → Reports →
  AI Reader usage dashboard* (gated by `local/aireader:viewlog`) gives an
  at-a-glance view of adoption, health, language demand, reach, storage, and
  estimated spend — all derived from data the plugin already stores, with no
  schema change and no new personal data (MDL-659).
  - **KPI strip**: ready narrations, audio minutes, estimated spend, learners
    reached, activities narrated, generation failure rate, audio storage, and
    instructor opt-outs.
  - **Charts** (Moodle Chart API): assets-by-status pie, ready-narrations-by-
    language bar, and a narrations-generated-over-time line. Links out to the
    existing audio log and cost-by-course reports.
  - **Reach is a starters metric, not completion.** It counts learners who have
    a saved playback position, and is labelled as such — a saved position
    resets to 0 when a track ends, so completion analytics are deliberately
    left to a later release. "Audio minutes" uses each asset's stored duration,
    falling back to the last aligned Whisper segment's end time.
  - New `dashboard_metrics` service (aggregate queries) with PHPUnit coverage
    for reach, adoption bucketing, language counts, storage, and the failure-rate
    edge cases.

## [1.3.4] — 2026-07-08

### Added

- **Course "Download all narration" page.** A new per-course page (linked from
  the course navigation as *Download narration audio*) lets learners download
  every AI narration they can access in one ZIP, for offline listening. Each
  file is listed up front with its size and the archive total, and a warning is
  shown before large downloads on metered connections. This completes the v1.3
  "Offline & quick wins" epic (MDL-658) alongside the per-activity download
  button and ID3 tagging shipped in 1.3.0.
  - Access control mirrors the `pluginfile` handler on a per-asset basis:
    the source activity must be user-visible, the learner must hold
    `local/aireader:listen` on the module context, narration must be enabled
    for that scope, and hidden Book chapters require
    `mod/book:viewhiddenchapters`. Only `ready` assets are packaged — the page
    never queues or triggers generation, so a student action cannot incur
    OpenAI cost. Aggregates no personal data, so the privacy provider is
    unchanged.
  - New `download_warn_threshold_mb` admin setting (default 100) controls the
    large-download warning; set to 0 to disable it. Respects the existing
    site-wide `allow_downloads` toggle. New `download_manager` service class
    with PHPUnit coverage for the access-gating and filtering rules.

## [1.3.3] — 2026-07-08

### CI

- Cleared the sole Moodle Plugin CI failure introduced in 1.3.2: a phpcs
  `moodle.Commenting.InlineComment.NotCapital` warning in `http_guard.php`
  (an inline comment began with the lowercase `parse_url()`), which fails the
  Code Checker step under `--max-warnings 0`. Reworded the comment to start
  with a capital. No functional change.

## [1.3.2] — 2026-07-08

### Security

- **SSRF guard hardened against IP-obfuscation bypasses.** `http_guard`'s
  outbound-URL check only range-checked hosts that `FILTER_VALIDATE_IP`
  recognised, so several routable notations for loopback/private addresses
  slipped past the loopback/RFC1918 block — bracketed IPv6 literals (`[::1]`,
  `[fd00::1]`), decimal-integer IPv4 (`2130706433`), octal-dotted IPv4
  (`0177.0.0.1`, which `filter_var` misreads as public `177.0.0.1` while curl
  routes it to loopback), and hexadecimal IPv4 (`0x7f000001`). The endpoint URLs
  are admin-only settings, so this is defense-in-depth aligned with the guard's
  stated threat model (a compromised admin account redirecting the outbound
  endpoint — and the OpenAI Bearer key — to an internal host). The guard now
  strips IPv6 brackets before validation, refuses obfuscated numeric/octal/hex
  IPv4 forms, and rejects malformed or zone-tagged IPv6 literals. The change
  only tightens the allowlist — legitimate hosts (`api.openai.com`, public
  dotted-quads, internal-DNS proxies) still pass. Seven PHPUnit cases added
  covering each bypass plus a public-IPv4 accept case. PHP-only; no schema
  change.

## [1.3.1] — 2026-06-04

### Fixed

- **Listening completion now works with Page/Book completion settings.**
  Moodle's Page and Book forms only expose the native "view the activity"
  automatic-completion rule, which could either block saving an audio-only
  requirement or complete the activity as soon as it was viewed. When AI Reader
  listening completion is enabled, the plugin now stores the activity as
  automatic completion, clears Moodle's native view-completion flag, and lets
  the configured listening percentage be the completion gate.

## [1.3.0] — 2026-06-04

### Added

- **Selectable player design + accent colour.** A new *Player appearance*
  settings group lets admins choose how the "Listen to this content" widget
  appears: the full player (default, unchanged) or one of four compact designs
  — slim banner bar, inline pill, collapsed accordion, or right-aligned inline
  action — each of which expands into the same full player inline on click, so
  every playback feature is preserved. A colour picker (`player_accent_color`,
  default Saylor orange) re-themes the play button, progress bar, and text
  highlighting from a single accent; hover and tint shades derive automatically
  via CSS `color-mix()`. The widget styling was refactored onto one
  `--la-accent` custom property to support this (also set on the document root
  so in-page karaoke `<mark>` highlights, injected outside the player, pick up
  the accent).
- **Auto-play compact designs on open.** New `autoplay_on_expand` admin setting
  (default on): when a learner opens a banner/pill/accordion/inline player,
  playback begins as soon as the audio is ready instead of needing a second
  click. Best-effort — browsers may still gate autoplay on prior interaction.
  No effect on the full player.
- **Per-activity audio download.** A Download button on the player saves the
  narration mp3 for offline listening, labelled with the file size
  ("Download (3.2 MB)"). Downloads get a human-readable filename
  (`Course - Activity[ - Chapter] (lang).mp3`) and respect the same
  listen-capability and visibility gating as playback. New `allow_downloads`
  admin setting (default on) to disable it site-wide. (MDL-662; remainder of
  the v1.3 "Offline & quick wins" epic — ID3 tags, course "Download all"
  page, tests — still to come.)
- **Listening-based activity completion.** New `enable_completion` admin
  setting (default off) lets teachers require listening to X% of an embedded AI
  Reader narration before the source Page/Book activity is marked complete.
  Progress is recorded as distinct played audio ranges rather than last saved
  position, so scrubbing ahead does not satisfy the rule. Downloaded/offline
  MP3 playback is not counted yet; syncable offline listening remains a future
  development.
- **ID3 tags on generated audio.** Narration mp3s are now tagged at generation
  time with title (activity, plus chapter for books and a language marker for
  translations), album (course), artist (site), genre, track number, and a
  comment carrying the AI-generated disclosure — so downloaded files are
  recognisable in a music player and the disclosure travels with them. Applies
  to audio generated or regenerated from this release onward. (MDL-663)

## [1.2.1] — 2026-05-29

### Fixed

- **Karaoke highlighting skipped a number of segments.** The in-place
  highlighter matched each Whisper segment as a single contiguous run inside
  one element and wrapped it with `range.surroundContents()`, which silently
  dropped any segment that (a) began with text not present in the body — the
  prepended title or a heading Moodle renders as excluded chrome; (b) spanned
  an element boundary (a heading into a paragraph, one list item into the next,
  an inline `<em>`); or (c) appeared verbatim more than once (a body sentence
  restated in the summary), where the search always resolved to the first copy.
  Three changes lift coverage substantially:
  - **Forward search cursor** — each segment is matched from where the previous
    one ended, so verbatim duplicates resolve to the correct occurrence.
  - **Suffix fallback** — when the whole segment can't be found, leading words
    are dropped and the longest remaining run is matched, recovering
    title/heading-glued segments.
  - **Per-text-node wrapping** — a matched run that crosses element boundaries
    is wrapped as several `<mark>`s (one per text node, sharing the segment
    index) that highlight together, instead of being dropped.

  JS-only; the version bump rolls jsrev so browsers fetch the rebuilt bundle.

## [1.2.0] — 2026-05-29

### Added

- **Admin-editable, date-effective TTS pricing.** A new *TTS pricing* setting
  (Site admin → Plugins → AI Reader) holds per-model rates as
  `model, rate, date` lines. The optional effective-from date means each asset
  is priced at the rate that was in force when it was generated: to raise a
  price going forward you add a new dated line, and historical costs stay
  unchanged. `*` is supported as a catch-all model. Rates default to the
  previously hard-coded values, so nothing changes until an admin overrides
  them. (There is no OpenAI pricing API to pull from, so rates are maintained
  here rather than fetched live.)
- **Cost-by-course report.** A new page under *Site administration → Reports →
  AI Reader cost by course* lists every course that has generated narration,
  the number of audios, and the estimated total cost, ordered by spend, with a
  grand total. Linked from the audio log report.

### Changed

- The audio log's per-asset cost and the summary total now use the configured,
  date-effective rates (previously hard-coded constants).

## [1.1.0] — 2026-05-29

### Added

- **Audio generation log report.** New page under *Site administration →
  Reports → AI Reader audio log* listing every narration asset: the course and
  activity (page, or book + chapter) it was generated for, language, model and
  voice, status, when it was generated, file size, and an estimated cost. Failed
  generations are listed with their failure reason. The table is sortable,
  paged, filterable by status, and downloadable (CSV, Excel, etc.). A summary
  header shows counts by status and the estimated total spend.
  - Gated by a new `local/aireader:viewlog` capability (granted to managers by
    default), so it is visible to managers as well as site admins.
  - **Cost estimation** (`cost_calculator`) is derived from the narration
    character count and the model used: tts-1 ($15) and tts-1-hd ($30) use
    OpenAI's published per-million-character rates; gpt-4o-mini-tts uses a
    blended $10 per-million-character estimate. Translation and Whisper
    alignment are billed separately and excluded. Figures are budgeting
    estimates, not an invoice.
  - New nullable `inputchars` column on `local_aireader_asset`, captured at
    generation, drives the estimate. Assets generated before this release have
    no character count and report an unknown cost (shown as "—").

## [1.0.5] — 2026-05-29

### CI

- Cleared the Moodle Plugin CI failures introduced by the 1.0.2–1.0.4 work:
  - **phpcs** — wrapped an over-length regex line in `openai_client`, capitalized
    the lead word of three inline comments, and dropped the unnecessary
    `MOODLE_INTERNAL` guard from the two class-only backup/restore files.
  - **Grunt** — rebuilt `amd/build/player.min.js` (+ source map) from
    `amd/src/player.js`, which had been edited (boilerplate header) without
    regenerating the build. The minified bundle now carries the license banner.

No functional change.

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
