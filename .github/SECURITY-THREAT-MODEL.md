# Threat model — composer-wp-plugin-activator

## Scope

This document lists attack classes considered for v1.0.x, their mitigation in
this package, and an explicit acceptance state. Out-of-scope classes are
listed at the end with rationale.

Severity floor for v1.0.x: **Critical + Medium** (per the project hardening
roadmap § 4.4). Low/informational findings are accepted but not actively
mitigated.

## In-scope classes

### Critical: Shell injection via consumer-supplied config

| Vector | Mitigation | Acceptance |
|--------|-----------|-----------|
| `extra.composer-wp-plugin-activator.wp-cli` contains shell metachars (`;`, `\|`, `` ` ``, `$()`, newline, null byte) | Regex allowlist `^[A-Za-z0-9_./-]+$` in `Config::parseWpCli()`. Reject + warning, skip activation (the injected binary path is never reached). | Test `ConfigTest::testWpCliRejectsShellMetacharacters` (data-provider over the payload set) passes. |
| `extra.composer-wp-plugin-activator.wp-path` contains shell metachars or leading `-` (option-spoof into WP-CLI) | Same regex in `Config::parseWpPath()` plus an explicit leading-`-` rejection that emits a warning. Invalid values are dropped (path stays `null`). | Tests `ConfigTest::testWpPathRejectsShellMetacharacters` and `ConfigTest::testWpPathRejectsOptionSpoofing` pass. |
| Plugin slugs supplied via `plugins` / `priority` arrays contain shell metachars | Existing `Config::isValidSlug()` rejects anything that is not `[A-Za-z0-9._-]`. Invalid entries are skipped with a warning; the rest still activate. | Existing slug tests + new injection-payload data-provider over `plugins`/`priority` entries. |
| Composer package name (`vendor/slug`) bypasses slug check after normalisation | `Config::parseSlug()` normalises `vendor/slug` to its basename and re-runs `isValidSlug()` on the result before accepting it. | Test `ConfigTest::testComposerStyleNamesNormalisedAndValidated` passes. |

### Critical: Shell injection at the WP-CLI invocation

| Vector | Mitigation | Acceptance |
|--------|-----------|-----------|
| Argument splicing through a shell-interpreted command line (e.g. `proc_open("wp plugin activate ${slug}", …)`) | Every dynamic / consumer-influenced token (binary path, slug, `--path=` value) is wrapped via `ProcessExecutor::escape()` (POSIX single-quote escaping: `'…'`, with embedded `'` rendered as `'\''`) before `WpCli::buildCommand()` joins them with `implode(' ', …)`. Hard-coded literals such as `--allow-root` are appended raw — they carry no consumer input. The joined string is then handed to `ProcessExecutor::execute()`, which routes through Symfony `Process::fromShellCommandline()` and **does** spawn `/bin/sh -c`. The defence is therefore not "no shell" but "every byte the consumer can influence is single-quote-quoted", so shell metacharacters in those tokens are interpreted literally, not as syntax. | `WpCli::run` audit entry in `docs/security/audit-initial.md` walks the `escape()` → `implode` → `fromShellCommandline` chain. |
| Re-injection of already-validated slugs after string concatenation into a command line | Slugs and flags are still concatenated into a single command string, but every dynamic token passes through `ProcessExecutor::escape()` first. The validator layer (`Config::parseSlug` / `isValidSlug`) is the primary defence; the single-quote wrap is defence-in-depth for anything that escaped it. | Same audit entry; `tests/WpCliTest` covers a payload-bearing slug being passed and observed as a single literal argv token. |

### Medium: Root escalation surprises

| Vector | Mitigation | Acceptance |
|--------|-----------|-----------|
| `allow-root: true` unconditionally appends `wp --allow-root`, silently permitting WP-CLI execution as root whenever the Composer process itself runs as root | The default is `allow-root: "auto"`, which only appends `--allow-root` when the current process is **actually** running as root (uid 0). Opt-in to unconditional root is therefore explicit. | README "Configuration" section documents the matrix; `Config::parseAllowRoot()` accepts only `true`, `false`, or `"auto"` and falls back to `"auto"` for everything else. |
| Composer install run as root → `allow-root: "auto"` triggers `--allow-root` | This is the **expected** behaviour for the Composer-in-Docker pattern and is documented. No additional mitigation. | README note + audit-doc disposition. |

### Medium: Supply chain compromise

| Vector | Mitigation | Acceptance |
|--------|-----------|-----------|
| A dev-dependency is published with a known CVE | `roave/security-advisories:dev-latest` lives in `require-dev`. Its `conflict` entries cause `composer install` / `composer update` to fail at **resolve time** when a known-vulnerable version would otherwise be selected. | `composer.json` lists the package; CI's install step fails closed when a conflict matches. |
| An already-installed dep becomes CVE-tagged after the lockfile is written | Weekly `.github/workflows/audit.yml` cron runs `composer audit --no-interaction`. A failure turns the run red and triggers GitHub's default workflow-failure notifications. | Workflow runs on `30 7 * * 1` UTC; visible in the Actions tab. |
| Third-party GitHub Action is compromised (tag pin re-pointed to a malicious commit) | Three high-traffic third-party actions are pinned to immutable SHAs: `shivammathur/setup-php`, `ramsey/composer-install`, `wagoid/commitlint-github-action`. The `semgrep/semgrep` container image is pinned to a specific release tag. Dependabot's `github-actions` ecosystem opens PRs to bump these SHAs when upstream cuts a new release. | `.github/workflows/*.yml` diff after sub-project C-5 lands. |

## Out-of-scope (known limitations)

The classes below are **not** actively mitigated in v1.0.x. The first five
are also surfaced in `README.md` → "Out-of-scope risks (v1.0.x)"; the
last two (null bytes via `ProcessExecutor::escape()`, `posix_getuid()`
on Windows) are catalogued here only — they are implementation-detail
caveats that do not change the public-facing API contract. Reports
remain welcome via private security advisory; we may pull a class back
into scope in a later major if evidence warrants it.

- **TOCTOU on the `wp` binary** — the version check (`wp --version`) and the
  activation invocation happen in separate process spawns. A binary replaced
  between the two would be executed. The mitigation belongs to the consumer
  (don't make `wp` writable by less-trusted users); the practical attack
  surface is bounded by local-filesystem write access, which is itself
  out-of-scope (see below).
- **Locale-spoofed WP-CLI output** — the "activated N plugin(s)" summary is
  parsed from English WP-CLI output. Under a non-English WP-CLI locale the
  summary may be misreported. Activation itself is unaffected; this is a
  reporting-fidelity issue, not a security boundary.
- **DoS via WP-CLI never returning** — there is no client-side timeout in
  v1.0.x. A hung `wp` invocation will hang the Composer install. Mitigation
  is consumer-side process supervision; we accept this risk over inventing a
  timeout policy that would surprise long-running activations.
- **Secrets leaked into Composer `--verbose` logs** — when the consumer runs
  Composer with `--verbose` / `-vvv`, WP-CLI's stdout (which may include
  database credentials or API keys emitted by custom plugins) appears in the
  log. The verbosity is consumer-controlled; we do not redact.
- **Local-filesystem write access prerequisite** — an attacker who can edit
  the consumer's `composer.json` has already compromised the host. The
  config-validation regexes are defence-in-depth, not a primary boundary.
- **Null bytes via `ProcessExecutor::escape()`** — Composer's `escape()` wraps
  its input in POSIX single quotes and preserves any embedded NUL byte
  verbatim (unlike PHP 8's `escapeshellarg`, which throws `ValueError` on
  NUL). When the resulting command string is then handed to PHP's `proc_open`
  / Symfony's Process layer, the underlying `execve`-style call terminates
  C strings at the first NUL, so a `\0` would truncate or malform the
  command before the binary runs. This is a property of the runtime, not a
  defect of this package, and the `wp-cli` and `wp-path` regex already
  rejects NUL at parse time. The practical risk is therefore low; we treat
  it as a known limitation rather than a fix target.
- **`posix_getuid()` absent on some Windows environments** — `allow-root:
  "auto"` relies on `posix_getuid()` to detect that the current process is
  running as uid 0. On Windows PHP builds where the `posix` extension is
  unavailable, the function is missing and `"auto"` falls back conservatively
  to **not** appending `--allow-root`. Consumers on such builds who need
  root-equivalent behaviour must set `allow-root: true` explicitly.

## Disclosure

See [`SECURITY.md`](../SECURITY.md) for the report channel (private GitHub
Security Advisories, with email fallback).

**SLA:**

- Triage: within **7 days** of report.
- Fix target — **Critical**: within **30 days**.
- Fix target — **Medium**: within **90 days**.

No bug bounty.
