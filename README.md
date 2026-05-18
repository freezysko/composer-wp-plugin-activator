# Composer WP Plugin Activator

[![CI](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/ci.yml/badge.svg)](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/ci.yml)
[![CodeQL](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/codeql.yml/badge.svg)](https://github.com/freezysko/composer-wp-plugin-activator/actions/workflows/codeql.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Latest Version](https://img.shields.io/packagist/v/freezysko/composer-wp-plugin-activator.svg)](https://packagist.org/packages/freezysko/composer-wp-plugin-activator)
[![Total Downloads](https://img.shields.io/packagist/dt/freezysko/composer-wp-plugin-activator.svg)](https://packagist.org/packages/freezysko/composer-wp-plugin-activator)
[![License](https://img.shields.io/packagist/l/freezysko/composer-wp-plugin-activator.svg)](LICENSE)
[![Conventional Commits](https://img.shields.io/badge/Conventional%20Commits-1.0.0-yellow.svg)](https://www.conventionalcommits.org)

Auto-activate Composer-installed WordPress plugins after `composer install` /
`composer update`. WordPress plugins pulled in via Composer (Bedrock-style:
`wpackagist-plugin/*`, `wp-plugin/*`) land on disk but stay inactive until
`wp plugin activate` runs. This Composer plugin hooks into Composer's
`post-install-cmd` / `post-update-cmd` events and runs WP-CLI activation for
you — no per-request overhead, no MU-plugin tricks.

## Quick start

Require the package:

```bash
composer require freezysko/composer-wp-plugin-activator
```

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
plugins via WP-CLI. No `scripts` wiring needed — the package self-registers.

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
  actual install directory. This is the safe, package-scoped default: it does
  not touch manually-dropped or third-party plugins.
- **`"all"`** — runs `wp plugin activate --all`. Activates every plugin in the
  plugins directory, Composer-managed or not. Opt-in for teams that want
  directory-wide activation; remember that activation can run migrations,
  schedule cron, or call external services.
- **array** — explicit, ordered list, e.g. `["woocommerce", "polylang"]`.
  Activated in the given order, so foundation plugins can precede dependents.
  Each entry is validated as a plugin slug; invalid entries (leading `-`,
  whitespace, disallowed characters) are skipped with a warning. Entries that
  contain `/` (e.g. `wpackagist-plugin/woocommerce`) are treated as Composer
  package names and normalized to the part after the last `/` — write the WP
  slug directly to avoid the warning.

**Invalid `plugins` values fail closed.** A typo such as `"plugins": "everthing"`
or an array of only invalid slugs is treated as "activate nothing" with a
warning. This prevents an unintentional `wp plugin activate --all` from a
configuration mistake.

### Plugin activation order

WordPress 6.5+ supports `Requires Plugins:` headers and WP-CLI enforces
them, but it activates plugins **alphabetically** — so a dependent plugin
can fail on its first pass when its dependency hasn't run yet. The
package handles this in two layers:

1. **`priority` list (recommended for known blockers).** Slugs listed in
   `priority` are activated first, in order, before the main `plugins`
   pass. Use it for foundational plugins like WooCommerce that many other
   plugins extend:

   ```json
   {
       "extra": {
           "composer-wp-plugin-activator": {
               "plugins": "composer",
               "priority": ["woocommerce"]
           }
       }
   }
   ```

   Each entry is validated as a plugin slug. Composer-style names
   containing `/` are normalized to the part after the last slash with a
   warning. Invalid entries are skipped with a warning.

2. **Bounded retry loop (safety net).** After the main pass, any
   activation pass that activates at least one plugin triggers another
   pass, up to five total passes. This catches blockers `priority`
   didn't cover. A pass that activates nothing new stops the loop — the
   remaining failures are real (missing plugin, real error).

`priority` is **ignored when `plugins` is an explicit array**, because
the array already controls activation order. A warning is emitted so
operators notice the redundant config.

A priority-pass failure does **not** stop the main pass from running —
the main pass and the retry loop may still converge the rest. The
failure is still surfaced as an error (regardless of `verbose`) and is
preserved in the final exit code, so `fail-on-error: true` still aborts
Composer when a priority slug fails to activate.

## How it works

On `post-install-cmd` / `post-update-cmd` the plugin:

1. Checks the WP-CLI binary is runnable (`wp --version`). If not → warning, skip.
2. Checks WordPress is installed (`wp core is-installed`). If not, and
   `skip-when-wp-not-installed` is true → info line, skip. This is the normal
   fresh-build case before `wp core install` has run.
3. Activates plugins per the `plugins` config. If a plugin fails because a
   dependency (WordPress 6.5+ `Requires Plugins:`) is not active yet, the
   activation command is re-run — each pass activates whatever just became
   unblocked — until everything is active or a pass makes no further
   progress (capped at 5 passes).
4. Reports a one-line summary. If everything was already active:
   `all plugins already active.` Otherwise: `activated N plugin(s).`

The plugin is **idempotent** — running it twice is safe; the second run
no-ops. It is **non-blocking** — it never aborts `composer install` unless
`fail-on-error` is set.

## Bedrock integration

Bedrock projects work out of the box. WP-CLI auto-detects the WordPress path
from `wp-cli.yml`, so `wp-path` can stay `null`. A typical Bedrock
`composer.json` needs only the `require` entry plus the `allow-plugins` entry
shown in Quick start.

If your CI or Docker build runs Composer as root, leave `allow-root` at
`"auto"` — the plugin detects root and adds `--allow-root` automatically.

## Edge cases and limitations

- **Multisite** — network activation is out of scope for v1. The plugin
  activates plugins site-locally only.
- **Plugin dependency order** — WordPress 6.5+ plugins can declare
  dependencies via the `Requires Plugins:` header. WP-CLI enforces these but
  activates alphabetically, so a dependent may fail on the first pass. The
  plugin handles this by re-running activation until WordPress's own
  dependency checks are satisfied (capped at 5 passes). It does **not** parse
  headers or use Composer's dependency graph itself. A genuinely missing
  dependency — declared via `Requires Plugins:` but not installed — is
  reported as an error once the retries make no further progress. On
  WordPress < 6.5 there is no dependency enforcement, so a single pass runs.
- **Fresh installs** — `wp core is-installed` requires a database connection.
  Before `wp core install` has run (or before the DB is provisioned), the
  check fails and the plugin skips cleanly. This is intended.
- **No-op summary is locale-dependent** — the "already active" vs "activated
  N" summary is parsed from WP-CLI's English output. Under a non-English
  WP-CLI locale the summary line may be less precise. Activation itself is
  unaffected — it is always idempotent.
- **No plugin installation** — this package only *activates*. Installing
  plugins is Composer's job (via `composer/installers` + wpackagist).
- **No auto-deactivation** — removing a plugin from `composer.json` removes it
  from disk; WordPress handles activation-state cleanup on the next request.

## Comparison to alternatives

| Tool | What it does | Why this is different |
|------|--------------|-----------------------|
| `tgmpa/tgm-plugin-activation` | Admin-side UX prompting users to install/activate plugins | This is composer-side automation with no admin UI. |
| `primetime/wp-plugin-activation-manifest` | File-based activation manifest | Unmaintained; does not hook Composer events. This hooks `post-install`/`post-update` directly. |
| Manual Composer `scripts` | Hand-wire `wp plugin activate` into `post-install-cmd` | This self-registers, parses config, and handles fresh-install / missing-binary cases gracefully. |
| `roots/bedrock-autoloader` | Autoloads `mu-plugins/` | Different problem — that handles must-use plugins, not regular plugin activation. |

## Contributing

1. `composer install`
2. `vendor/bin/phpunit` — run the test suite
3. `vendor/bin/phpstan analyse` — run static analysis

Issues and pull requests welcome.

## License

MIT — see [LICENSE](LICENSE).
