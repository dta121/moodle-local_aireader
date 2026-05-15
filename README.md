# local_aireader

A Moodle local plugin that adds a "Listen to this content" player at the top of
supported course resources:

- `mod_page` — reads the full page
- `mod_book` — reads the **current chapter** only

Audio is generated once via OpenAI's speech endpoint, cached as an MP3 in the
Moodle file store, and reused for every learner who can access the source
activity. Generation runs in a background ad hoc task; the page never blocks
on TTS.

**Multi-language narration.** Set an allowlist of language codes in admin and
learners see a language picker on the player. Translation happens once per
(content, target-language) pair via OpenAI chat-completions, cached in a
separate table, and reused across every voice and TTS model — five Spanish
voices = one translation + five audios.

## Requirements

- Moodle 4.5 or later (uses `\core_external\external_api` and the hook system).
- PHP 8.1+.
- Outbound HTTPS to your configured OpenAI-compatible endpoint.
- Cron (or the task runner) actually executing — generation is queued, not synchronous.

## Install

1. Copy this directory to `<moodle>/local/aireader/`.
2. Visit **Site administration → Notifications** and complete the upgrade.
3. Go to **Site administration → Plugins → Local plugins → AI Reader** and set:
   - `Enable plugin`
   - `OpenAI API key`
   - `Voice` (default `marin`) and `TTS model` (default `gpt-4o-mini-tts`)
   - (Optional) `Languages offered to learners` — e.g. `en,es,fr,pt,zh_cn`
4. Ensure cron is running. The first time a Page or Book chapter is viewed,
   a `local_aireader\task\generate_audio` task is queued.

## Architecture

```
hook (top of body) ──► AMD player.js ──► AJAX get_status(lang)
                                              │
                                              ▼
                                     content_extractor
                                              │ sourcehash (includes lang)
                                              ▼
                                       asset_manager
                                       ├─ ready?  → return URL
                                       └─ else    → queue ad hoc task
                                                       │
                                                       ▼
                                                generate_audio
                                                  → if lang ≠ $CFG->lang:
                                                      translation_manager
                                                        ↳ openai_translator
                                                  → openai_client (chunked TTS)
                                                  → storage (Moodle File API)
                                                  → record_generated
```

## Multi-language narration

Set `Site administration → Plugins → Local plugins → AI Reader →
Languages offered to learners` to a comma-separated list of Moodle language
codes (e.g. `en,es,fr,pt,zh_cn`). Learners see a language picker on the player
listing exactly those.

- The site's `$CFG->lang` is treated as the **source** language and never
  translated.
- Every other code in the allowlist triggers a one-time translation pass via
  the configured chat-completion model (default `gpt-4o-mini`). The translated
  text is cached in `local_aireader_translation` keyed on
  `sha256(cleantext) + targetlang + model` — one translation pass serves
  every voice and TTS model variant.
- Translation is **lazy by default**: each language is synthesized the first
  time a learner requests it. Turn on
  `Pre-generate every enabled language on save` to eagerly seed all enabled
  languages whenever a teacher saves a Page or Book chapter (cron then
  synthesizes them in the background).
- Variants of the same base language are not re-translated. `en_us` and
  `en_gb` share one row; `zh_cn` and `zh-tw` share another.

**Costs:** ~$0.0003 per 1000-word translation + ~$0.06 per 1000-word TTS,
both one-time. After first render, every student in every language streams
the same cached MP3 for free.

## Transcript and karaoke highlighting (optional)

When `Enable Whisper alignment (karaoke highlighting)` is on, every generated
mp3 is also sent through Whisper (`/v1/audio/transcriptions` with
`timestamp_granularities=['segment']`) by a separate `align_audio` ad hoc
task. The segments are cached in `local_aireader_segment` keyed on the
asset id, so every learner shares the same alignment.

Learners then see a **"Transcript"** toggle on the player. Two display
modes, chosen automatically:

- **In-place** (default, `highlight_in_place` admin setting on): the player
  searches for each segment's text in the rendered page DOM and wraps the
  matching range in `<mark class="local-aireader-mark">`. As the audio
  plays the current segment gets the `is-current` class. The page text
  itself "lights up" — no duplicated transcript. Requires at least 90% of
  segments to match the page DOM; falls back to the pane otherwise.

- **Transcript pane**: a collapsible panel below the player listing every
  segment as a clickable button. Used automatically when in-place matching
  fails — typically on translated narrations (Spanish audio cannot
  highlight an English page), pages with video-cue insertions ("A video
  appears here..."), or pages where Moodle filters mangle the DOM enough
  to break range matching. Also used when `highlight_in_place` is off.

Both modes support **click-to-seek** (clicking a segment jumps the audio
there) and **auto-scroll** to keep the current segment in view, suspended
for 10 seconds after any user-initiated scroll so we don't fight someone
re-reading a paragraph.

The transcript pane open/closed preference is persisted per-browser in
`localStorage` under `local_aireader_transcriptopen`.

**Costs:** ~$0.006 per minute of audio (Whisper), one-time per asset.
Cached forever; deleted when the asset is purged.

The "ready" lookup is keyed on
`sha256(module|cmid|chapterid|lang|voice|model|cleantext)`. If the cleaned
content changes, the hash changes, the old row is marked `stale`, and a new
row + task are created.

Event observers (`db/events.php`) also mark assets stale on
`course_module_updated` / `chapter_updated` so the next viewer triggers a
fresh generation immediately. The hash check is the authoritative invalidator;
events are an optimisation.

## Storage

Audio is stored through the Moodle File API:

- `component = local_aireader`
- `filearea  = audio`
- `itemid    = local_aireader_asset.id`

Files are served via `local_aireader_pluginfile()` (see `lib.php`), which
requires `local/aireader:listen` on the **source** module context and verifies
the user can see the activity via `get_fast_modinfo`. No direct/public URLs are
issued.

If your Moodle is configured with an alternative file system (e.g. an
S3-backed file store), generated MP3s land there automatically — the plugin
does not need its own bucket integration.

## Web services

| Function                       | Type  | Capability                |
|--------------------------------|-------|---------------------------|
| `local_aireader_get_status`    | read  | `local/aireader:listen`   |
| `local_aireader_request_regen` | write | `local/aireader:manage`   |

Both are AJAX-enabled and intended for the in-page player.

## File tree

```
local/aireader/
├── README.md
├── version.php
├── settings.php
├── lib.php                    file serving + plugin hooks
├── db/
│   ├── install.xml            local_aireader_asset table
│   ├── access.php             capabilities
│   ├── services.php           external function definitions
│   ├── events.php             observers for invalidation
│   └── hooks.php              output hook callback registration
├── classes/
│   ├── observer.php
│   ├── hook_callbacks.php
│   ├── task/
│   │   └── generate_audio.php
│   ├── external/
│   │   ├── get_status.php
│   │   └── request_regen.php
│   └── manager/
│       ├── content_extractor.php
│       ├── asset_manager.php
│       ├── openai_client.php
│       └── storage.php
├── templates/
│   └── player.mustache
├── amd/src/
│   └── player.js              build to amd/build/player.min.js via `grunt amd`
└── lang/en/
    └── local_aireader.php
```

## Building JS

```
cd <moodle>/local/aireader
npx grunt amd
```

This produces `amd/build/player.min.js` which Moodle's AMD loader will serve.

## Capabilities

- `local/aireader:listen` — students+ can hear narration on activities they
  can already access.
- `local/aireader:manage` — editing teachers can force regeneration from the
  player.
- `local/aireader:purge` — managers can purge stored assets.

## v1 limitations / known scope

- **English only.** The `lang` parameter is plumbed through the hash, asset
  row, and API surface, but only English strings ship.
- **Book chapter-only.** Reading a whole book in one asset is intentionally
  deferred — chapter scope keeps cache invalidation cheap and matches how
  learners navigate Books.
- **MP3 concatenation is naive.** Chunks are concatenated as raw byte streams.
  This plays fine in modern browsers but `durationsecs` is not populated. If
  duration metadata becomes important, re-encode with `ffmpeg` in the task.
- **No Moodle mobile app support.** The player is web-only.

## Design notes worth keeping

- The page-load path never calls OpenAI synchronously. Even uncached visits
  return `pending` and let the JS poll.
- The `sourcehash` is computed from **cleaned text**, not raw HTML, so cosmetic
  HTML edits don't force regeneration.
- All access checks resolve against the **source** module context, never the
  storage context, so renaming or moving the file area would not affect
  authorisation.
