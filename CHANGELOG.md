# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0](https://github.com/freezysko/composer-wp-plugin-activator/compare/v1.0.0...v1.1.0) (2026-05-21)


### Features

* add commitlint workflow and config ([a30542d](https://github.com/freezysko/composer-wp-plugin-activator/commit/a30542d7b04325c36737742e319d24be8a909ccc))
* initial release — freezysko/composer-wp-plugin-activator v1.0.0 ([d31190f](https://github.com/freezysko/composer-wp-plugin-activator/commit/d31190fd02d429b10344fdc078588b1bb0e1709d))
* **quality:** add PHPMD cyclomatic complexity gate (threshold 10); fix findings inline ([e4129ac](https://github.com/freezysko/composer-wp-plugin-activator/commit/e4129ac5d32267b9ccf14f0989de74a0e1538588))
* **quality:** add PHPStan strict/deprecation/phpunit/cognitive-complexity (threshold 15); fix findings inline ([81c8c01](https://github.com/freezysko/composer-wp-plugin-activator/commit/81c8c013e427c7ed8654c253792e44ec3b72985f))


### Bug Fixes

* **codeql:** drop incorrect 'queries: default' input — default suite is implicit ([52a59c1](https://github.com/freezysko/composer-wp-plugin-activator/commit/52a59c187720b3725111cf9e982fdfcaa77ad622))
* **commitlint:** disable body-max-line-length for bot-generated commits ([f4e556e](https://github.com/freezysko/composer-wp-plugin-activator/commit/f4e556e8d7923beb1699c4b5cf5c9c25ba5691c8))
* **config:** reject shell metacharacters in consumer-supplied keys ([db27e78](https://github.com/freezysko/composer-wp-plugin-activator/commit/db27e78ee01683593cb3959a8e77a86a20cf4d6f))
* **deps:** bump phpunit min to ^10.1 for &lt;source&gt; config support ([d3a106c](https://github.com/freezysko/composer-wp-plugin-activator/commit/d3a106c4f957ee4f5b6d7088fa21b56cf8bd92da))
* **phpstan:** disable treatPhpDocTypesAsCertain for cross-Composer-version compat ([689400e](https://github.com/freezysko/composer-wp-plugin-activator/commit/689400e075fe569e3bdb01419831e62e4acc0ef7))
* **quality:** wrap composer-unused with error_reporting=0 (PHP 8.5 vendor noise) ([f08aa21](https://github.com/freezysko/composer-wp-plugin-activator/commit/f08aa21ceab7c01e8071d1388c5c9306850a8853))
* **security:** apply C-8 audit findings (F-1 wording, F-3/F-4 dash guard, F-2/F-5 docs) ([8d2235a](https://github.com/freezysko/composer-wp-plugin-activator/commit/8d2235a779e1ee8d691a13ba5cc097df6cc4c51e))
* **semgrep:** use --config auto (registry-based) instead of explicit rule packs ([26b4079](https://github.com/freezysko/composer-wp-plugin-activator/commit/26b40797bdce7fcea6b35ab124136e33222ceedd))

## [Unreleased]

## [1.0.0] - 2026-05-15

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
