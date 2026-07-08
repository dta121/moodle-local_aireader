# AI Reader Release Notes

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
