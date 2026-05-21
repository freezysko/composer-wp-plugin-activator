# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-21

### Added

- Initial release.
- Composer plugin that auto-activates WordPress plugins on `post-install-cmd`
  and `post-update-cmd` via WP-CLI.
- Configuration via the `extra.composer-wp-plugin-activator` section:
  `wp-cli`, `wp-path`, `plugins`, `skip-when-wp-not-installed`, `verbose`,
  `fail-on-error`, `allow-root`.
- `plugins` modes: `"composer"` (default — only Composer-managed plugins),
  `"all"`, and explicit ordered list.
- Explicit `plugins` array entries are validated and normalized: Composer
  package names containing `/` (e.g. `wpackagist-plugin/...`) are stripped to
  the WP slug, and entries with disallowed characters (leading `-`,
  whitespace, etc.) are skipped with a warning.
- Composer-derived slugs (from `wordpress-plugin` package install paths) are
  validated with the same rule as user-supplied entries; an invalid basename
  is skipped with a warning instead of being passed to WP-CLI.
- Invalid `plugins` configuration falls back to "activate nothing" (empty
  list) rather than `"all"`, so a typo cannot silently broaden activation.
- Optional `priority` config: an ordered list of slugs activated before
  the main `plugins` pass. Cleaner output and one less subprocess for
  projects with known foundational plugins (e.g. WooCommerce). Ignored
  when `plugins` is an explicit array.
- Bounded retry loop that converges plugin activation order using WordPress
  6.5+ `Requires Plugins:` dependency checks — no header parsing or Composer
  dependency-graph lookup, capped at 5 passes.
- Auto-detection of root user to add `--allow-root` for CI/Docker.
- Graceful handling of missing WP-CLI binary and uninstalled WordPress.

[Unreleased]: https://github.com/freezysko/composer-wp-plugin-activator/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/freezysko/composer-wp-plugin-activator/releases/tag/v1.0.0
