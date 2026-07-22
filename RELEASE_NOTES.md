# AI Reader Release Notes

## 1.8.0

Released: 2026-07-22

AI Reader 1.8.0 ships together with 1.4.3–1.7.0 in a single release. The
headline: learner voice choice, a language checklist, three new compact
player designs, a redesigned admin settings page, and a security fix.

- **Security (1.4.3):** the narration enable state (plugin master switch,
  per-module switches, per-activity/chapter overrides) is now enforced
  server-side in every web service and the pluginfile handler. Previously a
  learner with the listen capability could call the AJAX endpoints directly
  to queue paid OpenAI generation, stream audio, read transcripts, or earn
  listen-based completion on scopes where narration was switched off.
- **Languages & translation (1.5.0):** "Languages offered to learners" is a
  checklist of every officially supported OpenAI speech language, plus a
  free-text escape hatch for future codes. New professional academic
  translation system prompt; translation model default is now `gpt-5-mini`
  with a model-aware request payload (reasoning models reject `temperature`;
  gpt-5 models get `reasoning_effort: minimal`). Sites still on the old
  defaults are migrated automatically; customised values are untouched.
- **Learner voice picker (1.6.0):** tick two or more voices under "Voices
  offered to learners" and the player shows a voice picker. Each
  (language, voice) pair is generated lazily on first request, cached
  forever, and guarded by a server-side allowlist.
- **Redesigned settings page (1.7.0):** six section cards with a sticky
  sidebar (live filter + scrollspy), collapsed advanced settings per
  section, status chips, per-setting "Modified" badges with defaults and
  one-click reset, chip lists for the language/voice checklists, and a
  sticky save bar. Progressive enhancement — the native form still does the
  saving, and renders unchanged if the JS can't run.
- **Compact players (1.8.0):** three new player designs — a one-row slim
  bar whose transport appears on play, a pill that expands into the slim
  bar, and a pill + bottom-docked mini-player that follows the learner down
  long pages. All reuse the same playback/transcript/completion logic.
- **Performance:** privacy export/delete, asset purges, and course
  downloads now batch their queries instead of querying per context, per
  asset, or per chapter (GitHub #8).

Upgrading registers the new settings with safe defaults; existing
configuration (including the previous comma-separated language list)
carries over automatically.

## 1.4.2

Released: 2026-07-08

- Admin toggle for whether in-page karaoke highlights are click-to-seek
  (`highlight_interactive`, default on). Off = follow-along highlighting
  only; click-to-seek stays available in the transcript pane.

## 1.4.1

Released: 2026-07-08

- In-page karaoke highlighting is now injected only after the learner
  engages the reader (pressing play, or returning to a saved resume
  position). Learners who never start the player see a completely
  unmodified page.

## 1.4.0

Released: 2026-07-08

- Admin usage dashboard under *Site administration → Reports*: adoption,
  health, language demand, reach, storage, and estimated spend at a glance
  (MDL-659).

## 1.3.4

Released: 2026-07-08

- Course-level "Download narration audio" page: learners can download all
  the narration they may access in a course as a single ZIP, with the same
  per-asset access checks as streaming (MDL-658).

## 1.3.3

Released: 2026-07-08

AI Reader 1.3.3 clears a Moodle Plugin CI failure introduced in 1.3.2.

- A phpcs inline-comment style warning in `http_guard.php` failed the Code
  Checker step (which runs with `--max-warnings 0`). The comment was reworded;
  there is no functional change and no schema change.

## 1.3.2

Released: 2026-07-08

AI Reader 1.3.2 is a security-hardening release for the plugin's outbound HTTP
guard.

- The guard that validates the configured OpenAI endpoint URLs now recognises IP
  addresses written in obfuscated notations — bracketed IPv6 literals, and
  decimal, octal, and hexadecimal IPv4 forms — that previously slipped past the
  loopback/private-range block even though curl still routes them. This closes
  server-side request forgery (SSRF) bypasses of the existing guard.
- Endpoint URLs are admin-only settings, so this is defense-in-depth against a
  compromised or misconfigured admin account, in line with the guard's original
  threat model.
- No functional change for normal configurations: `api.openai.com` and other
  real hostnames are unaffected. No schema change; the version bump only
  registers the new release.

## 1.3.1

Released: 2026-06-04

AI Reader 1.3.1 fixes the Moodle completion setup for listening-based
completion on Page and Book activities.

- When AI Reader listening completion is enabled, the plugin now saves the
  activity as automatic completion and clears Moodle's native "view the
  activity" condition.
- Viewing the Page or Book no longer satisfies the requirement before the
  configured listening percentage is reached.
- Teachers may leave Moodle's native completion option at **None** or choose
  **Add requirements** while enabling the AI Reader listening rule; AI Reader
  normalizes the stored completion mode on save.

## 1.3.0

Released: 2026-06-04

AI Reader 1.3.0 focuses on playback presentation, offline-friendly downloads,
and the first version of listening-based activity completion.

## Highlights

- Admins can choose between the full player and compact banner, pill,
  accordion, or inline designs, all using the same playback controls once
  expanded.
- A new accent colour setting themes the player controls and in-page narration
  highlights.
- Compact players can auto-play on expand when the browser allows it.
- Learners can download narration MP3s when downloads are enabled. Downloaded
  files use human-readable filenames and remain gated by the same activity
  visibility and listening permissions as streamed playback.
- Teachers can require learners to listen to a configured percentage of an AI
  Reader narration before Moodle marks a Page or Book activity complete.
- Listening completion uses distinct played ranges from the embedded Moodle
  player, so scrubbing ahead does not satisfy the rule.
- Downloaded or otherwise offline MP3 playback does not count toward listening
  completion in this release. Syncable offline listening is retained as a
  future enhancement.
- Generated MP3s now include ID3 metadata for easier recognition in local music
  players.

## Upgrade Notes

- Site administrators must enable **Allow listening-based activity completion**
  before teachers see the per-activity completion fields.
- Moodle activity completion must also be enabled on the target Page or Book
  activity for AI Reader to mark it complete.
- The upgrade adds `local_aireader_listen` for per-learner listened ranges and
  `local_aireader_completion` for per-activity completion settings.
- The plugin now stores listened ranges as privacy-covered user data and
  includes them in Moodle Privacy API export/delete flows.
