# Project Structure

```
local/aireader/
├── amd/
│   ├── src/player.js          # AMD module: player UI, playback, karaoke, MediaSession
│   └── build/                 # Auto-generated minified output (do not edit)
├── backup/moodle2/            # Backup & restore support classes
├── classes/
│   ├── external/              # Web service (AJAX) endpoint classes
│   │   ├── get_status.php     # Returns audio status + URL for a resource
│   │   ├── get_transcript.php # Returns Whisper-aligned segments
│   │   ├── request_regen.php  # Marks asset stale, queues regeneration
│   │   ├── set_override.php   # Enable/disable narration per resource
│   │   └── set_position.php   # Persists learner playback position
│   ├── manager/               # Business logic layer (stateless service classes)
│   │   ├── asset_manager.php  # Asset lifecycle (create, status, hash, purge)
│   │   ├── content_extractor.php  # Extracts clean text from mod_page/mod_book
│   │   ├── cost_calculator.php    # Estimates API cost from pricing config
│   │   ├── cost_report.php        # Aggregates cost data for admin report
│   │   ├── http_guard.php         # Validates outbound URLs (HTTPS, no private IPs)
│   │   ├── openai_aligner.php     # Whisper transcription for alignment
│   │   ├── openai_client.php      # TTS synthesis (chunked)
│   │   ├── openai_translator.php  # Chat-completions-based translation
│   │   ├── override_manager.php   # Per-resource enable/disable logic
│   │   ├── position_manager.php   # Resume-position persistence
│   │   ├── segment_manager.php    # Stores/retrieves aligned segments
│   │   ├── storage.php            # Moodle File API wrapper for MP3 storage
│   │   └── translation_manager.php # Translation cache + orchestration
│   ├── output/log_table.php   # Renderable for the admin generation log
│   ├── privacy/provider.php   # Moodle Privacy API implementation
│   ├── task/
│   │   ├── generate_audio.php     # Ad-hoc task: TTS generation pipeline
│   │   ├── align_audio.php        # Ad-hoc task: Whisper alignment post-generation
│   │   └── purge_stale_assets.php # Scheduled task: cleanup old stale assets
│   ├── hook_callbacks.php     # Injects player HTML + JS on page view
│   └── observer.php           # Event observers (invalidation on content change)
├── db/
│   ├── access.php             # Capability definitions
│   ├── events.php             # Event observer registrations
│   ├── hooks.php              # Hook callback registrations (Moodle 4.5+ hook system)
│   ├── install.xml            # XMLDB schema (5 tables)
│   ├── services.php           # External service function declarations
│   ├── tasks.php              # Scheduled task declarations
│   └── upgrade.php            # Database upgrade steps
├── lang/en/local_aireader.php # English language strings
├── templates/
│   ├── player.mustache        # Player UI template
│   └── manager_offline.mustache # "Narration disabled" state template
├── tests/
│   ├── external/              # PHPUnit tests for web service endpoints
│   └── manager/               # PHPUnit tests for manager classes
├── lib.php                    # pluginfile serving + coursemodule form hooks
├── settings.php               # Admin settings page
├── report.php                 # Generation log report page
├── costs.php                  # Cost report page
├── styles.css                 # Plugin CSS (auto-loaded)
├── version.php                # Plugin version & requirements
└── CHANGELOG.md
```

## Architectural Layers

1. **Presentation**: `hook_callbacks.php` injects a mount div + calls `amd/src/player.js` via `js_call_amd`. The player communicates with the backend exclusively through AJAX web services.

2. **API (External Services)**: `classes/external/` — thin request/response wrappers that validate params, check capabilities, and delegate to managers.

3. **Business Logic (Managers)**: `classes/manager/` — stateless service classes. Each manager owns one concern (assets, overrides, positions, translation, cost, storage, OpenAI HTTP calls).

4. **Background Processing**: `classes/task/` — ad-hoc tasks for TTS generation and Whisper alignment; one scheduled task for stale-asset cleanup.

5. **Data**: Moodle DML (`$DB`) against the five `local_aireader_*` tables. File storage via Moodle's File API (component `local_aireader`, filearea `audio`).

## Conventions

- One class per file, PSR-4 autoloaded under `local_aireader\` namespace.
- External service classes extend `\core_external\external_api` and implement `execute_parameters()`, `execute()`, `execute_returns()`.
- Manager classes are instantiated directly (no DI container); static methods for simple lookups.
- Tests mirror the source tree: `tests/external/`, `tests/manager/`.
- Every PHP file starts with the GPL boilerplate and a `@package local_aireader` PHPDoc tag.
