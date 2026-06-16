# Product Overview

**local_aireader** is a Moodle local plugin that adds AI-generated text-to-speech narration to Page and Book chapter activities. Learners get a podcast-style audio player injected at the top of supported resources.

## Core Value

- Audio is generated once via OpenAI TTS, cached on the Moodle server, and reused for all students — no per-student API cost.
- Generation happens in background cron tasks, never blocking page loads.

## Key Features

- Play/pause, scrub, skip ±15s, variable speed (0.75×–2×), resume-where-left-off.
- Optional karaoke-style in-page highlighting (Whisper alignment) with click-to-seek transcript pane.
- Multi-language support: pages are translated and re-narrated in configured languages.
- Instructor controls: per-page/chapter enable/disable toggle, regenerate button.
- Lock-screen / Bluetooth headphone controls via MediaSession API.
- Cost reporting dashboard for administrators.

## Supported Modules

- `mod_page`
- `mod_book` (per-chapter granularity)

## Target Users

- **Learners**: accessibility (low vision, dyslexia), on-the-go listening, ESL support.
- **Instructors**: zero-setup narration with per-resource override control.
- **Admins**: API key configuration, cost monitoring, language/voice settings.

## External Dependencies

- OpenAI API (TTS, optional translation via chat completions, optional Whisper alignment).
- Compatible with any OpenAI-API-compatible gateway (Azure OpenAI, LiteLLM, OpenRouter, vLLM).
