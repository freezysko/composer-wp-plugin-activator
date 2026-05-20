# Contributing

Thanks for your interest in `composer-wp-plugin-activator` — a Composer plugin
that auto-activates Composer-installed WordPress plugins via WP-CLI on
`post-install` / `post-update`. Contributions are welcome, whether that's a bug
report, a feature request, or a pull request. This guide covers how to set up a
local environment, run the checks, and get changes merged.

## Development setup

```bash
git clone https://github.com/freezysko/composer-wp-plugin-activator.git
cd composer-wp-plugin-activator
composer install
```

PHP 8.1+ is required. The package supports PHP 8.1 and newer; day-to-day
development targets PHP 8.5.

## Running checks locally

All checks are wired as `composer` scripts:

- `composer test` — runs the PHPUnit suite.
- `composer ci` — runs the suite plus PHPStan (`@test` + `@phpstan`). This is
  the main pre-push gate.
- `composer quality` — runs `cs-check` (PHP-CS-Fixer dry-run), `phpmd`,
  `composer-unused`, and `composer-require-checker`.
- `composer cs-fix` — applies PHP-CS-Fixer fixes in place.

Coverage is not wired as a `composer` script — it runs in CI. To produce a
local coverage report you need a coverage driver (Xdebug or PCOV):

```bash
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

Mutation testing (Infection) is CI-only — see the `.github/workflows/mutation.yml`
workflow. A local run additionally needs the Infection PHAR and a single active
coverage driver.

## Contribution workflow

Post-`v1.0.0`, all changes go through pull requests:

1. Fork the repository and create a topic branch.
2. Make your change; keep it focused and add tests where it makes sense.
3. Ensure `composer ci` is green locally.
4. Open a pull request against `main`.

Requirements enforced on every PR:

- **CI must be green.**
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org)**
  — `commitlint` enforces this.
- **Commits must be signed** — branch protection requires signatures.

For bug reports and feature requests, please use the issue templates:
[bug report](.github/ISSUE_TEMPLATE/bug_report.yml) /
[feature request](.github/ISSUE_TEMPLATE/feature_request.yml).

## Architecture

The package is a Composer plugin (`type: composer-plugin`). It hooks Composer's
`post-install-cmd` / `post-update-cmd` events and runs WP-CLI plugin activation.

### Module map

All classes live under `src/` in the `Freezysko\ComposerWpPluginActivator`
namespace:

| Class | Responsibility |
|-------|----------------|
| `Plugin` | Composer plugin entry point and event subscriber. A thin adapter — it stores `Composer`/`IOInterface` on activation and forwards `post-install` / `post-update` events to `PluginRunner`. |
| `PluginRunner` | Wires the pieces together (`Config`, `WpCli`, `Activator`, `ComposerPluginResolver`), runs the activation, and applies the `fail-on-error` policy by converting a non-zero exit code into a thrown exception (Composer aborts a build by throwing). |
| `Config` | Parses and validates the `extra.composer-wp-plugin-activator` section. Every value is validated; invalid input falls back to a safe default or fails closed with a warning. |
| `ComposerPluginResolver` | Resolves the WP plugin slugs installed by Composer — packages of type `wordpress-plugin` — from each package's install-path basename. |
| `Activator` | Orchestrates the activation run: optional priority pass, main pass, and the bounded retry loop. Returns an exit code and never throws. |
| `WpCli` | Shells out to the WP-CLI binary (`--version`, `core is-installed`, `plugin activate`). Builds and escapes the command, applies `wp-path` and `--allow-root`. |
| `WpCliResult` | Immutable value object for a WP-CLI invocation — exit code plus combined output. |

### Data flow

On `post-install-cmd` / `post-update-cmd`:

1. `Plugin` forwards the event to `PluginRunner`.
2. `PluginRunner` builds a `Config` from the root package's `extra` section and
   constructs `WpCli`, `Activator`, and `ComposerPluginResolver`.
3. `Activator` checks the WP-CLI binary is runnable (`wp --version`). If not, it
   emits a warning and skips.
4. `Activator` checks WordPress is installed (`wp core is-installed`). If not,
   and `skip-when-wp-not-installed` is true, it emits an info line and skips —
   the normal fresh-build case before `wp core install`.
5. `Activator` activates plugins per the `plugins` config (`"composer"`,
   `"all"`, or an explicit list), running an optional `priority` pass first.
6. If a plugin fails because a WordPress 6.5+ `Requires Plugins:` dependency is
   not active yet, the activation pass is re-run — each pass picks up whatever
   just became unblocked — until everything is active or a pass makes no
   progress, capped at `Activator::MAX_ACTIVATION_ATTEMPTS` (5) passes.
7. `Activator` reports a one-line summary and returns an exit code.
8. `PluginRunner` enforces `fail-on-error`: if the exit code is non-zero and
   `fail-on-error` is set, it throws and Composer aborts.

The plugin is idempotent (a second run no-ops) and non-blocking — it never
aborts `composer install` unless `fail-on-error` is set.

### Extension points

- **Configuration** — all behaviour is driven by the
  `extra.composer-wp-plugin-activator` keys parsed in `Config`. The README
  documents each key.
- **`plugins` modes** — `"composer"` (Composer-managed plugins only), `"all"`
  (`wp plugin activate --all`), or an explicit ordered slug list.
- **Activation order** — the `priority` list activates foundational plugins
  first; the bounded retry loop in `Activator` is the safety net for the rest.

## Code of Conduct

This project adheres to a [Code of Conduct](CODE_OF_CONDUCT.md); by
participating you are expected to uphold it.
