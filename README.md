# local_aireader

[![Moodle Plugin CI](https://github.com/dta121/moodle-local_aireader/actions/workflows/moodle-ci.yml/badge.svg?branch=main)](https://github.com/dta121/moodle-local_aireader/actions/workflows/moodle-ci.yml)

AI-narrated **"Listen to this content"** player at the top of supported Moodle
course resources:

- `mod_page` — reads the full page
- `mod_book` — reads the **current chapter**

Audio is generated once via OpenAI's speech endpoint, cached as an MP3 in the
Moodle File API, and reused for every learner who can access the source
activity. Generation runs in a background ad hoc task; the page never blocks
on TTS.

## Features

**Narration**

- Cache-first MP3 generation via OpenAI TTS (`gpt-4o-mini-tts`, voice `marin`
  by default).
- Background generation through Moodle ad hoc tasks; the page-load path never
  calls OpenAI synchronously.
- Robust HTML → speech text extraction with sentence-break preservation, raw
  URL stripping, and inline cues for embedded video / audio / iframes
  ("A video appears here…") so narration flows past mixed-media activities.
- Cache key includes a SHA-256 hash of the cleaned text, so cosmetic HTML
  edits don't force regeneration.

**Multi-language**

- Site admin sets a comma-separated allowlist of language codes; learners see
  a language picker on the player.
- Translation via OpenAI chat-completions (`gpt-4o-mini`), cached in a
  separate table keyed on `sha256(cleantext) + targetlang + model` — one
  translation pass serves every voice and TTS model.
- Variants of the same base language (e.g. `en_us`/`en_gb`) share a row.
- Lazy by default; optional eager pre-generation on save.
- LRU GC removes translation rows unused past the stale-retention window.

**Player UX**

- Saylor.org-styled circular play button + scrub bar with click-and-drag
  seeking and keyboard fine-seek (Home/End jump, arrows ±5s on the scrubber).
- Skip ±15 seconds.
- Playback speed select (0.75× → 2×), persisted per-browser in
  `localStorage`.
- MediaSession API integration for lock-screen and Bluetooth controls.
- Keyboard shortcuts when the player has focus: `k` play/pause,
  `j`/`←` skip back 15s, `l`/`→` skip forward 15s.
- "~N min listen" estimate before generation finishes, derived from the
  cleaned text length.
- Resume where you left off — per-user, per-asset, cleared automatically when
  the asset is regenerated.

**Karaoke-style highlighting** (optional)

- Whisper transcription with segment timestamps produces a sentence-level
  alignment of every audio file. Stored in `local_aireader_segment`.
- Two display modes, chosen automatically:
  - **In-place**: searches the rendered DOM for each segment's text and
    wraps the match in `<mark>`. As audio plays, the current segment gets
    `is-current`. Page text "lights up" — no duplicated transcript.
  - **Transcript pane**: collapsible panel with click-to-seek buttons.
    Used when matching falls below 50%, or for translated narrations
    against a source-language page, or for pages where Moodle filters
    transform the DOM enough to break range matching.
- Auto-scrolls the current segment into view; suspends auto-scroll for 10 s
  after any user scroll so it doesn't fight someone re-reading a paragraph.

**Instructor controls**

- Per-resource enable/disable override on every Page activity and on every
  Book chapter, plus an activity-level default for whole Books.
- In-player toggle for managers/teachers to turn narration off for the
  current page/chapter without opening the edit form.
- Force-regenerate button on the player.
- Stale-asset garbage collection scheduled task with configurable retention.

**Security & privacy**

- All five external (web service) functions validate parameters, context,
  and capabilities before any work.
- `lang` parameter is server-side validated against the admin allowlist —
  learners cannot iterate ISO codes to amplify OpenAI cost.
- Book chapter visibility (`book_chapters.hidden`) is enforced everywhere:
  hidden chapters require `mod/book:viewhiddenchapters`.
- File serving uses `require_login($course, false, $cm)` so enrolment,
  course visibility, and activity availability rules all apply.
- Hard per-asset content cap (`max_narration_chars`, default 50000) refuses
  runaway pages before OpenAI is called.
- Outbound HTTP is locked to HTTPS via `CURLPROTO_HTTPS`; loopback, private,
  link-local, and cloud-metadata IPs are blocked at runtime regardless of
  what's in admin settings.
- OpenAI error bodies are sanitized (Bearer tokens, `sk-…` keys, and long
  opaque identifiers redacted) before being stored on the asset row or
  written to cron logs.
- Full GDPR privacy provider: export, delete-by-user, delete-in-context,
  and userlist all implemented for the two tables that hold per-user data.

## Requirements

| Component | Versions |
|---|---|
| Moodle    | **4.5 LTS, 5.0, 5.1, 5.2** (all CI-tested on every push). Floor declared by `$plugin->requires` is 4.5 (build 2024100700). |
| PHP       | 8.1, 8.2, 8.3, 8.4 (CI-tested). |
| Database  | PostgreSQL 15+ or MariaDB 10.11+ (CI-tested). |
| Network   | Outbound HTTPS to your OpenAI-compatible endpoint. |
| Cron      | Must be running — generation is queued, not synchronous. |

The plugin is `MATURITY_STABLE` as of v1.0.0.

## Install

1. Copy this directory to `<moodle>/local/aireader/` (or install via the
   plugin uploader at *Site administration → Plugins → Install plugins*).
2. Visit **Site administration → Notifications** and complete the upgrade.
3. Go to **Site administration → Plugins → Local plugins → AI Reader** and
   set at minimum:
   - **Enable plugin** — on
   - **OpenAI API key** — `sk-…`
   - **Voice** (default `marin`) and **TTS model** (default `gpt-4o-mini-tts`)
   - (Optional) **Languages offered to learners** — comma-separated list,
     e.g. `en,es,fr,pt,zh_cn`
   - (Optional) **Enable Whisper alignment** — turns on karaoke highlighting
4. Ensure cron is running. The first time a Page or Book chapter is viewed,
   a `local_aireader\task\generate_audio` task is queued.

## Admin settings reference

| Setting | Default | Purpose |
|---|---|---|
| `enabled` | on | Master switch. |
| `enable_page` / `enable_book` | on / on | Per-module-type defaults. |
| `openai_api_key` | — | Bearer key for OpenAI / compatible proxy. |
| `model`, `voice` | `gpt-4o-mini-tts`, `marin` | TTS engine. |
| `openai_endpoint` | `https://api.openai.com/v1/audio/speech` | TTS URL. |
| `chunk_size` | 3800 chars | Sentence-aware TTS chunking limit. |
| `max_narration_chars` | 50000 | Per-asset hard cap on cleaned text length. |
| `poll_interval` | 5 s | Player status-polling cadence. |
| `auto_generate_on_save` | on | Queue regen when a teacher saves source content. |
| `stale_retention_days` | 14 | Stale assets older than this are GC'd nightly. |
| `disclosure` | "Audio narration is AI-generated…" | Shown below player. |
| `narration_prompt` | warm academic guide | System prompt for TTS. |
| `enabled_languages` | `en` | Allowlist of Moodle language codes. |
| `translation_model` | `gpt-4o-mini` | Chat-completion model for translation. |
| `translation_endpoint` | `…/v1/chat/completions` | Translation URL. |
| `translation_prompt` | preserve-terminology academic | System prompt for translation. |
| `eager_languages_on_save` | off | Pre-generate every enabled language on save. |
| `enable_alignment` | off | Whisper transcription for karaoke highlighting. |
| `highlight_in_place` | on | Prefer DOM `<mark>` over transcript pane. |
| `alignment_model` | `whisper-1` | Whisper model id. |
| `alignment_endpoint` | `…/v1/audio/transcriptions` | Whisper URL. |

## Architecture

```
hook (top of body) ──► AMD player.js ──► AJAX get_status(lang)
                                              │
                                              ▼
                                     content_extractor (HTML → speech text)
                                              │ sourcehash (includes lang)
                                              ▼
                                       asset_manager
                                       ├─ ready?  → return URL
                                       └─ else    → queue generate_audio
                                                       │
                                                       ▼
                                                generate_audio (ad hoc)
                                                  → if lang ≠ $CFG->lang:
                                                      translation_manager
                                                        ↳ openai_translator
                                                  → openai_client (chunked TTS)
                                                  → storage (Moodle File API)
                                                  → record_generated
                                                  → (if enable_alignment)
                                                      queue align_audio
                                                                │
                                                                ▼
                                                          align_audio (ad hoc)
                                                            → openai_aligner (Whisper)
                                                            → segment_manager.store
```

### Cache invalidation

The "ready" lookup is keyed on `sha256(module | cmid | chapterid | lang | voice
| model | cleantext)`. If the cleaned content changes the hash changes, the
old row is marked `stale`, and a new row + task are created.

Event observers (`db/events.php`) also mark assets stale on
`course_module_updated` and `chapter_updated`, so the next viewer triggers a
fresh generation immediately. The hash check is the authoritative invalidator;
events are an optimisation. The `purge_stale_assets` scheduled task deletes
stale rows (plus their stored MP3s) older than `stale_retention_days`, and
LRU-purges the translation cache.

## Storage

Audio is stored through the Moodle File API:

- `component = local_aireader`
- `filearea  = audio`
- `itemid    = local_aireader_asset.id`

Files are served via `local_aireader_pluginfile()` (see `lib.php`), which:

1. Resolves `$course` and `$cm` from the asset.
2. Calls `require_login($course, false, $cm)` — enforces enrolment, course
   visibility, and any activity availability conditions.
3. Requires `local/aireader:listen` on the source module context.
4. For book assets, enforces chapter visibility against
   `mod/book:viewhiddenchapters`.

If your Moodle is configured with an alternative file system (e.g. an
S3-backed file store), generated MP3s land there automatically — the plugin
does not need its own bucket integration.

## Web services

All AJAX-enabled; all enforce capability + context + (where relevant)
chapter visibility and lang allowlist.

| Function                          | Type  | Capability                |
|-----------------------------------|-------|---------------------------|
| `local_aireader_get_status`       | read  | `local/aireader:listen`   |
| `local_aireader_get_transcript`   | read  | `local/aireader:listen`   |
| `local_aireader_set_position`     | write | `local/aireader:listen`   |
| `local_aireader_set_override`     | write | `local/aireader:manage`   |
| `local_aireader_request_regen`    | write | `local/aireader:manage`   |

## Capabilities

- `local/aireader:listen` — students+ can hear narration on activities they
  can already access.
- `local/aireader:manage` — editing teachers can toggle the per-resource
  override and force regeneration from the player.

## Privacy

The Privacy API provider (`classes/privacy/provider.php`) declares two tables
that hold per-user data:

- `local_aireader_position` — per-learner resume position
  (`userid, assetid, position, timemodified`).
- `local_aireader_override` — `usermodified` records which manager last
  toggled narration on/off for a page or chapter.

Plus one external location:

- **OpenAI** — cleaned activity text and (optionally) a target language code
  are sent to the configured OpenAI-compatible endpoints. No user identifiers
  are sent.

The provider implements `core_userlist_provider` and `plugin\provider`:
export, delete-by-user, delete-for-context, and delete-by-userlist all work.

## File tree

```
local/aireader/
├── README.md
├── version.php
├── settings.php
├── styles.css
├── lib.php                              file serving + mod-form injection
├── db/
│   ├── install.xml                      five tables (asset, override,
│   │                                    translation, position, segment)
│   ├── upgrade.php                      schema + savepoint history
│   ├── access.php                       capabilities
│   ├── services.php                     external function definitions
│   ├── events.php                       observers for invalidation
│   ├── hooks.php                        top-of-body hook callback
│   └── tasks.php                        scheduled task registration
├── classes/
│   ├── observer.php                     cm + chapter create/update/delete
│   ├── hook_callbacks.php               player injection on view.php
│   ├── privacy/
│   │   └── provider.php                 GDPR
│   ├── task/
│   │   ├── generate_audio.php           TTS + (chain) Whisper
│   │   ├── align_audio.php              Whisper transcription
│   │   └── purge_stale_assets.php       nightly GC
│   ├── external/
│   │   ├── get_status.php
│   │   ├── get_transcript.php
│   │   ├── set_position.php
│   │   ├── set_override.php
│   │   └── request_regen.php
│   └── manager/
│       ├── content_extractor.php        HTML → speech text
│       ├── asset_manager.php            asset lifecycle + chapter gate
│       ├── override_manager.php         per-resource enable/disable
│       ├── position_manager.php         resume positions
│       ├── segment_manager.php          Whisper alignment rows
│       ├── translation_manager.php      translation cache + LRU
│       ├── openai_client.php            TTS
│       ├── openai_translator.php        chat-completions translation
│       ├── openai_aligner.php           Whisper
│       ├── http_guard.php               HTTPS-only + error sanitization
│       └── storage.php                  Moodle File API facade
├── templates/
│   ├── player.mustache
│   └── manager_offline.mustache
├── amd/
│   ├── src/player.js                    source — lint with `npx eslint`
│   └── build/player.min.js              built artefact (committed)
├── tests/                               PHPUnit coverage of security gates
│   ├── external/get_status_test.php
│   └── manager/
│       ├── asset_manager_test.php
│       ├── http_guard_test.php
│       ├── override_manager_test.php
│       └── translation_manager_test.php
└── lang/en/local_aireader.php
```

## Building the AMD bundle

```
cd <moodle>/local/aireader
npx grunt amd
```

Produces `amd/build/player.min.js` (and `.map`), both committed to the repo
so `moodle-plugin-ci grunt` sees a clean tree.

## Running the tests

```
cd <moodle>
vendor/bin/phpunit --group local_aireader local/aireader/tests/
```

The CI workflow runs the suite on every push against PHP 8.1–8.4 × Moodle
4.5 LTS, 5.0, 5.1, 5.2 × PostgreSQL 16 / MariaDB 10.11.

## Costs (rule of thumb)

| Item | Approx. per 1000 words / per minute audio |
|---|---|
| TTS (`gpt-4o-mini-tts`) | ~$0.06 per 1000 words narrated, one-time per asset |
| Translation (`gpt-4o-mini`) | ~$0.0003 per 1000 words, one-time per (text, target-lang) |
| Whisper alignment | ~$0.006 per minute of audio, one-time per asset |

All cached and reused — after first render, every learner streams the same
MP3 for free.

## Limitations and known scope

- **Book chapter scope.** A whole book is not narrated as one asset;
  chapter-level scope keeps cache invalidation cheap and matches how learners
  navigate.
- **MP3 chunk concatenation is naive.** Chunks are concatenated as raw byte
  streams. This plays fine in modern browsers but `durationsecs` is not
  populated. If duration metadata becomes important, re-encode with `ffmpeg`
  in the task.
- **No Moodle mobile app support.** The player is web-only.

## Design notes worth keeping

- The page-load path never calls OpenAI synchronously. Even uncached visits
  return `pending` and let the JS poll.
- The `sourcehash` is computed from **cleaned text**, not raw HTML, so
  cosmetic HTML edits don't force regeneration.
- All access checks resolve against the **source** module context, never the
  storage context, so renaming or moving the file area would not affect
  authorisation.
- Translation, TTS, and Whisper are all behind a single admin-configurable
  API key but use independent endpoint settings, so a proxy or alternative
  chat-completion gateway can be wired in without touching speech config.
- The `http_guard` URL allowlist is enforced at runtime in each client,
  not just at admin save time — saved misconfigurations cannot turn into
  outbound SSRF.

## License

GPL v3 or later — see [LICENSE](LICENSE) or
https://www.gnu.org/copyleft/gpl.html.
