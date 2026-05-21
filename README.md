# Composer WP Plugin Activator

[![CI](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/ci.yml/badge.svg)](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/ci.yml)
[![CodeQL](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/codeql.yml/badge.svg)](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/codeql.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Latest Version](https://img.shields.io/packagist/v/freezysko/composer-wp-plugin-activator.svg)](https://packagist.org/packages/freezysko/composer-wp-plugin-activator)
[![Total Downloads](https://img.shields.io/packagist/dt/freezysko/composer-wp-plugin-activator.svg)](https://packagist.org/packages/freezysko/composer-wp-plugin-activator)
[![License](https://img.shields.io/packagist/l/freezysko/composer-wp-plugin-activator.svg)](LICENSE)
[![Conventional Commits](https://img.shields.io/badge/Conventional%20Commits-1.0.0-yellow.svg)](https://www.conventionalcommits.org)

A Composer plugin that auto-activates Composer-installed WordPress plugins via
WP-CLI on `composer install` / `composer update` — no `scripts` wiring, no
per-request overhead, no MU-plugin tricks.

## Quick start

Require the package:

```bash
composer require freezysko/composer-wp-plugin-activator
```

Releases are published as signed git tags through an automated release
pipeline and synced to Packagist on each tagged release. If a new release does
not appear on Packagist, trigger a manual sync from the "Update" button on the
[Packagist package page](https://packagist.org/packages/freezysko/composer-wp-plugin-activator)
— the same update the API ping in [`.github/workflows/release.yml`](.github/workflows/release.yml)
performs.

Then allow the plugin to run. Composer 2.2+ blocks plugin code unless it is
explicitly allowed — **without this entry the package installs but does
nothing:**

```json
{
    "config": {
        "allow-plugins": {
            "freezysko/composer-wp-plugin-activator": true
        }
    }
}
```

That's it. The next `composer install` / `composer update` will activate your
plugins via WP-CLI. The package self-registers — no `scripts` wiring needed.

For ready-to-copy `composer.json` setups see
[`examples/`](examples/): [Bedrock](examples/bedrock.md),
[vanilla WordPress](examples/vanilla-wp.md), and
[custom activation order](examples/custom-priority.md).

## Requirements

- PHP 8.1+
- Composer 2.x
- WP-CLI available on the machine running Composer

## Configuration

All keys are optional. Add an `extra.composer-wp-plugin-activator` section to
your `composer.json`:

```json
{
    "extra": {
        "composer-wp-plugin-activator": {
            "wp-cli": "wp",
            "wp-path": null,
            "plugins": "composer",
            "priority": [],
            "skip-when-wp-not-installed": true,
            "verbose": false,
            "fail-on-error": false,
            "allow-root": "auto"
        }
    }
}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `wp-cli` | string | `"wp"` | Path to the WP-CLI binary. |
| `wp-path` | string \| null | `null` | Value for WP-CLI's `--path=`. `null` lets WP-CLI auto-detect via `wp-cli.yml`. |
| `plugins` | string \| array | `"composer"` | `"composer"`, `"all"`, or an explicit ordered list of slugs. See below. |
| `priority` | array | `[]` | Slugs to activate before the main `plugins` pass (foundational plugins like WooCommerce). See "Plugin activation order". |
| `skip-when-wp-not-installed` | bool | `true` | If `wp core is-installed` is false, exit cleanly. Recommended for fresh installs. |
| `verbose` | bool | `false` | Stream full WP-CLI output even on success. |
| `fail-on-error` | bool | `false` | If true, a WP-CLI failure aborts Composer with a non-zero exit code. |
| `allow-root` | bool \| string | `"auto"` | `--allow-root` handling. `"auto"` adds it when running as root; `true`/`false` force it on/off. |

### `plugins` modes

- **`"composer"`** (default) — activates only plugins installed by Composer
  (packages of type `wordpress-plugin`). Slugs are derived from each package's
  install directory. Safe and package-scoped: it does not touch
  manually-dropped or third-party plugins.
- **`"all"`** — runs `wp plugin activate --all`, activating every plugin in the
  plugins directory, Composer-managed or not. Opt-in; remember that activation
  can run migrations, schedule cron, or call external services.
- **array** — explicit, ordered list, e.g. `["woocommerce", "polylang"]`.
  Activated in order, so foundation plugins can precede dependents. Invalid
  entries (leading `-`, whitespace, disallowed characters) are skipped with a
  warning. Entries containing `/` (e.g. `wpackagist-plugin/woocommerce`) are
  treated as Composer package names and normalized to the part after the last
  `/` — write the WP slug directly to avoid the warning.

**Invalid `plugins` values fail closed.** A typo such as `"plugins": "everthing"`
is treated as "activate nothing" with a warning, preventing an unintentional
`wp plugin activate --all` from a configuration mistake.

### Plugin activation order

WordPress 6.5+ supports `Requires Plugins:` headers and WP-CLI enforces them,
but it activates plugins **alphabetically** — so a dependent plugin can fail on
its first pass when its dependency hasn't run yet. The package handles this in
two layers:

1. **`priority` list (recommended for known blockers).** Slugs listed in
   `priority` are activated first, in order, before the main `plugins` pass —
   use it for foundational plugins like WooCommerce that many others extend.
   See [`examples/custom-priority.md`](examples/custom-priority.md). Invalid or
   Composer-style (`/`-containing) entries are normalized or skipped with a
   warning.
2. **Bounded retry loop (safety net).** After the main pass, any pass that
   activates at least one plugin triggers another, up to five total passes.
   A pass that activates nothing new stops the loop — remaining failures are
   real (missing plugin, real error).

`priority` is **ignored when `plugins` is an explicit array**, since the array
already controls order; a warning is emitted so operators notice. A
priority-pass failure does not stop the main pass, but it is surfaced as an
error and preserved in the exit code, so `fail-on-error: true` still aborts
Composer when a priority slug fails.

## Versioning

This package follows [Semantic Versioning](https://semver.org/).

**Public contract.** The public API is the
`extra.composer-wp-plugin-activator` configuration schema documented in
[Configuration](#configuration) — the keys `wp-cli`, `wp-path`, `plugins`,
`priority`, `skip-when-wp-not-installed`, `verbose`, `fail-on-error`,
`allow-root` — and the documented activation behavior. The PHP classes are
**not** part of the public API: consumers configure the plugin, they do not
call its code.

- **MAJOR** — a change that can break an existing consumer's `extra`
  configuration or the documented behavior: a removed or renamed config key, a
  changed default, or a changed activation outcome.
- **MINOR** — new, backward-compatible configuration or behavior.
- **PATCH** — bug fixes that do not change the documented contract.

A deprecated config key or behavior is kept for at least one MINOR release,
emitting a runtime warning, before removal in the next MAJOR. Deprecations and
breaking changes are recorded in [`CHANGELOG.md`](CHANGELOG.md).

## Bedrock integration

Works zero-config in [Bedrock](https://roots.io/bedrock/) projects — WP-CLI
auto-detects the WordPress path, so `wp-path` can stay `null`. See
[`examples/bedrock.md`](examples/bedrock.md).

## Limitations

- **Multisite** — network activation is out of scope for v1; plugins are
  activated site-locally only.
- **No plugin installation** — this package only *activates*. Installing
  plugins is Composer's job (via `composer/installers` + wpackagist).
- **No auto-deactivation** — removing a plugin from `composer.json` removes it
  from disk; WordPress handles activation-state cleanup on the next request.
- **No dependency-header parsing** — the package re-runs activation until
  WordPress's own `Requires Plugins:` checks pass (capped at 5 passes); it does
  not parse headers or use Composer's dependency graph itself.
- **English-locale summary** — the "already active" vs "activated N" summary is
  parsed from English WP-CLI output. Under a non-English locale the summary may
  be less precise; activation itself is unaffected.

The full list of in-scope and out-of-scope attack classes for v1.0.x is in
[`.github/SECURITY-THREAT-MODEL.md`](.github/SECURITY-THREAT-MODEL.md). To
report a vulnerability see [`SECURITY.md`](SECURITY.md).

## Troubleshooting

- **`wp-cli not found` warning, activation skipped** — the WP-CLI binary check
  (`wp --version`) failed. Set `wp-cli` to the binary's full path (e.g.
  `/usr/local/bin/wp`) if `wp` is not on the `PATH` of the process running
  Composer.
- **`WordPress not installed yet` info line, activation skipped** — `wp core
  is-installed` returned false. This is expected on a fresh build before
  `wp core install` has run or before the database is provisioned. It is
  controlled by `skip-when-wp-not-installed` (default `true`); set it to
  `false` to treat this as an error instead.
- **`wp-cli reported an error`, a plugin failed to activate** — set `verbose`
  to `true` to stream the full WP-CLI output and see the underlying failure
  (e.g. a genuinely missing `Requires Plugins:` dependency). By default the
  failure is reported but Composer still succeeds; set `fail-on-error` to
  `true` to make a WP-CLI failure abort Composer with a non-zero exit code.

## Contributing

Contributions are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for
development setup, local checks, the contribution workflow, and an architecture
overview. By participating you agree to the
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT — see [LICENSE](LICENSE).
