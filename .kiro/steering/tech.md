# Tech Stack & Build

## Platform

- **Framework**: Moodle (local plugin, component `local_aireader`)
- **Minimum Moodle**: 4.5 LTS (version 2024100700)
- **PHP**: 8.1–8.4
- **Databases**: PostgreSQL 15+ or MariaDB 10.11+
- **License**: GPL v3+

## Languages & Patterns

- **Backend**: PHP — follows Moodle coding standards (Frankenstyle namespacing, `defined('MOODLE_INTERNAL') || die()` guard, PHPDoc on every file/class/method).
- **Frontend**: ES module AMD (vanilla JS, no framework) in `amd/src/`. Built/minified by Moodle's Grunt toolchain into `amd/build/`.
- **Templates**: Mustache (`templates/*.mustache`).
- **Styles**: Plain CSS (`styles.css`), auto-loaded by Moodle.
- **Strings**: `lang/en/local_aireader.php` — all user-visible text must go through `get_string()`.

## Key Moodle APIs Used

- External services API (`classes/external/`) for AJAX endpoints.
- Ad-hoc tasks (`classes/task/`) for background audio generation and alignment.
- Hook system (`db/hooks.php` + `hook_callbacks.php`) for player injection.
- Event observers (`db/events.php` + `observer.php`) for cache invalidation.
- File API (`pluginfile.php`) for serving generated MP3s.
- Privacy API (`classes/privacy/provider.php`).
- Capabilities (`db/access.php`): `local/aireader:listen`, `local/aireader:manage`, `local/aireader:viewlog`.

## CI / Quality Gates

CI runs via GitHub Actions (`moodle-plugin-ci ^4`) on every push/PR to `main`. The matrix covers Moodle 4.5–5.2 × PHP 8.1–8.4 × PostgreSQL/MariaDB.

Checks executed (all must pass):
- `moodle-plugin-ci phplint`
- `moodle-plugin-ci phpcs --max-warnings 0` (Moodle code style)
- `moodle-plugin-ci phpdoc --max-warnings 0`
- `moodle-plugin-ci phpmd` (advisory, continue-on-error)
- `moodle-plugin-ci validate` (version.php, db schema)
- `moodle-plugin-ci savepoints` (upgrade step validation)
- `moodle-plugin-ci mustache` (template lint)
- `moodle-plugin-ci grunt --max-lint-warnings 0` (AMD build)
- ESLint on `amd/src` with `--max-warnings=0`
- `moodle-plugin-ci phpunit --fail-on-warning`
- `moodle-plugin-ci behat --profile chrome`

## Common Commands

These assume you have a working Moodle dev environment with the plugin installed at `local/aireader/`.

```bash
# Run PHPUnit tests for this plugin only
vendor/bin/phpunit --testsuite local_aireader_testsuite

# Run a single test class
vendor/bin/phpunit local/aireader/tests/manager/asset_manager_test.php

# Rebuild AMD (from Moodle root, requires Node + Grunt)
npx grunt amd --root=local/aireader

# Run ESLint on AMD source
npx eslint --max-warnings=0 local/aireader/amd/src

# Run Moodle code sniffer locally
vendor/bin/phpcs --standard=moodle local/aireader

# Purge caches after DB or config changes
php admin/cli/purge_caches.php
```

## Database

Schema defined in `db/install.xml`. Five tables:
- `local_aireader_asset` — generated TTS assets (status lifecycle: pending → generating → ready | error | stale)
- `local_aireader_override` — per-resource enable/disable flags
- `local_aireader_translation` — cached translations (LRU-style GC)
- `local_aireader_position` — per-user resume positions
- `local_aireader_segment` — Whisper-aligned sentence segments for karaoke

Schema changes require a new step in `db/upgrade.php` and a bumped `$plugin->version` in `version.php`.
