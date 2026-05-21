# Threat model — composer-wp-plugin-activator

## Scope and trust boundary

`composer-wp-plugin-activator` is a Composer plugin that auto-activates
Composer-installed WordPress plugins by shelling out to WP-CLI on Composer's
`post-install` / `post-update` events.

The security-relevant trust boundary is the consumer's `composer.json`: the
`extra.composer-wp-plugin-activator` block is **untrusted input** as far as this
package is concerned. The package parses and validates that block in
`src/Config.php`, then constructs a WP-CLI command line in `src/WpCli.php` and
runs it through Composer's process layer. Every value the consumer can place in
that block — `wp-cli`, `wp-path`, `plugins`, `priority`, `allow-root`, and the
boolean flags — is treated as a potential injection vector.

This document lists the attack classes considered for the **v1.0.x** release
line, the mitigation each has in this package, and an explicit acceptance
state. Out-of-scope classes are listed at the end, each with the vector and the
rationale for accepting it.

Severity floor for v1.0.x: **Critical and Medium**. Low and informational
findings are accepted but not actively mitigated. Anyone with only this file
and the public repository has everything needed to understand the security
posture — the document does not depend on any other file.

## In-scope classes

### Critical — Shell injection via consumer-supplied config

Consumer-supplied configuration must not be able to smuggle shell syntax into
the WP-CLI command line. The first line of defence is parse-time validation in
`Config`: every value that reaches the WP-CLI `argv` is checked against an
allowlist before it is accepted, and a failing value is dropped with a warning
(fail-closed — activation is skipped, Composer is never aborted).

| Vector | Mitigation | Verification |
|--------|------------|--------------|
| `wp-cli` value contains shell metacharacters (`;`, `\|`, `` ` ``, `$()`, `&`, newline, null byte, whitespace, `<`, `>`) | `Config::parseWpCli()` accepts the value only if it matches the allowlist regex `^[A-Za-z0-9_./-]+$` (constant `VALID_PATH_REGEX`). A non-matching value is rejected with a warning and the binary falls back to the literal `wp`; the injected path is never executed. | `ConfigTest::testWpCliRejectsShellMetacharacters` (driven by the `shellMetaPayloadsProvider` data provider, which covers `;`, backtick, `$()`, `\|\|`, `&&`, newline and NUL payloads). |
| `wp-cli` or `wp-path` value begins with `-`, spoofing an option flag into WP-CLI | `Config::parseWpCli()` / `Config::parseWpPath()` explicitly reject any value starting with `-`, before the regex check, with a warning. `wp-cli` falls back to `wp`; `wp-path` falls back to `null` (no `--path=` is appended). | `ConfigTest::testWpCliRejectsLeadingDashOptionSpoofing` and `ConfigTest::testWpPathRejectsLeadingDashOptionSpoofing`. |
| `wp-path` value contains shell metacharacters | `Config::parseWpPath()` applies the same `VALID_PATH_REGEX` allowlist; a non-matching value is rejected with a warning and the path stays `null`. | `ConfigTest::testWpPathRejectsShellMetacharacters` (same `shellMetaPayloadsProvider`). |
| Plugin slugs supplied via the `plugins` / `priority` arrays contain shell metacharacters | Each array entry is normalised and validated by `Config::parseSlug()` → `Config::isValidSlug()`, which accepts only `^[a-zA-Z0-9][a-zA-Z0-9_.-]*$`. Invalid entries are skipped with a warning; the remaining valid entries still activate. | `ConfigTest::testPluginsEntryRejectsShellMetacharacters` and `ConfigTest::testPriorityEntryRejectsShellMetacharacters` (both use `shellMetaPayloadsProvider`), plus the `invalidSlugProvider` cases in `ConfigTest::testIsValidSlugRejectsBadSlugs`. |
| A Composer-style package name (`vendor/slug`) bypasses the slug check after the vendor prefix is stripped | `Config::parseSlug()` strips everything up to and including the last `/`, then re-runs `isValidSlug()` on the resulting basename **before** accepting it — so a stripped name is validated, not trusted. | `ConfigTest::testPluginsArrayStripThenInvalidIsRejectedWithSingleWarning` passes `wpackagist-plugin/-bad`: the strip yields `-bad`, which `isValidSlug()` then rejects. |

### Critical — Shell injection at the WP-CLI invocation

The command line is built in `WpCli::buildCommand()` and executed via Composer's
`ProcessExecutor::execute()`. The mechanism is **not** "no shell": the joined
command string is ultimately handed to a shell (`/bin/sh -c`, via Symfony's
`Process::fromShellCommandline()`). The defence is that **every byte the
consumer can influence is quoted** before it reaches that shell.

| Vector | Mitigation | Verification |
|--------|------------|--------------|
| Argument splicing through the shell-interpreted command line (e.g. a slug or path breaking out of its token into shell syntax) | Each consumer-influenced token — the WP-CLI binary path, every slug, and the `--path=` value — is passed through `ProcessExecutor::escape()` before `WpCli::buildCommand()` joins the parts with `implode(' ', …)`. `escape()` wraps the token in POSIX single quotes (`'…'`, with any embedded `'` rendered as `'\''`), so shell metacharacters inside it are read as literal bytes, not syntax. Only hard-coded literals carry no consumer input and are appended raw: the WP-CLI subcommand verbs (`plugin`, `activate`, …) and the `--allow-root` flag. The joined string is then run by `ProcessExecutor::execute()`, which routes through `Process::fromShellCommandline()` and does spawn a shell. | `WpCliTest::testSlugsWithSpacesAreEscapedAsSingleArguments`: a slug containing a whitespace metacharacter (`my plugin`) is observed arriving at the binary as a **single** `argv` token, not two — confirming the single-quote wrap holds the token together across the shell. |
| Re-injection of an already-validated slug after it is concatenated into the command string | The `Config` validator layer (`parseSlug` / `isValidSlug`) is the primary defence: a payload-bearing slug is rejected before it ever reaches `WpCli`. The `ProcessExecutor::escape()` single-quote wrap is defence-in-depth — it would still neutralise any token that somehow escaped validation. Note this is genuine defence-in-depth, not a tested injection path: the metacharacter payloads (`;`, `$()`, …) are blocked one layer up in `Config`, so no `WpCli`-layer test drives them. The escaping is exercised by the whitespace-token test above. | Same `WpCliTest::testSlugsWithSpacesAreEscapedAsSingleArguments`; the parse-time rejection is covered by the `Config` tests in the row group above. |

### Medium — Root escalation surprises

| Vector | Mitigation | Verification |
|--------|------------|--------------|
| `allow-root: true` unconditionally appends `--allow-root`, silently permitting WP-CLI to run as root whenever the Composer process itself runs as root | The default mode is `"auto"`: `WpCli::shouldAllowRoot()` appends `--allow-root` only when the current process is **actually** uid 0 (`posix_getuid() === 0`). Opting into unconditional root therefore requires an explicit `allow-root: true`. `Config::parseAllowRoot()` accepts only the literals `true`, `false`, or `"auto"` and falls back to `"auto"` with a warning for anything else — so a stringy alias cannot quietly enable root execution. | `ConfigTest::testAllowRootAcceptsLiteral` and `ConfigTest::testAllowRootRejectsNonLiteralWithWarning`; `WpCliTest::testAllowRootIsAppendedWhenModeIsAlways`, `testAllowRootIsOmittedWhenModeIsNever`, and `testAllowRootIsOmittedUnderDefaultAutoModeOnNonRootEnvironment`. |
| A Composer install run as root → `"auto"` resolves to root and appends `--allow-root` | This is the **expected** behaviour for the Composer-in-Docker pattern (Composer routinely runs as root in containers) and is documented in the README configuration section. No additional mitigation: suppressing `--allow-root` here would simply break activation in the environment where it is most needed. | Documented behaviour; `WpCliTest::testAllowRootIsOmittedUnderDefaultAutoModeOnNonRootEnvironment` pins the non-root half of the matrix. |

### Medium — Supply-chain compromise

| Vector | Mitigation | Verification |
|--------|------------|--------------|
| A dev-dependency is published with — or is found to carry — a known CVE | `roave/security-advisories:dev-latest` is in `require-dev`. Its `conflict` entries make `composer install` / `composer update` fail at **resolve time** if a known-vulnerable version would otherwise be selected. | `composer.json` lists the package; the CI install step fails closed when a `conflict` matches. |
| An already-installed dependency becomes CVE-tagged after the lockfile was written | The weekly `.github/workflows/audit.yml` cron runs `composer audit`. A new advisory turns the run red, which triggers GitHub's default workflow-failure notification to the maintainer. | Workflow runs on `30 7 * * 1` UTC; visible in the repository's Actions tab. |
| A third-party GitHub Action is compromised by a re-pointed tag | The three third-party actions in use — `shivammathur/setup-php`, `ramsey/composer-install`, `wagoid/commitlint-github-action` — are pinned to immutable commit SHAs. The `semgrep/semgrep` container image is pinned to a specific release tag. Dependabot's `github-actions` ecosystem opens PRs to bump these pins when upstream cuts a release. | The pinned refs are visible in `.github/workflows/*.yml`. |

## Out-of-scope (known limitations)

The classes below are **not** actively mitigated in v1.0.x. They are recorded
here, in full, with their vector and the rationale for accepting them — this
section is the single authoritative list of the project's accepted security
risks. Reports remain welcome via private security advisory; a class may be
pulled back into scope in a later major release if evidence warrants it.

- **TOCTOU on the `wp` binary.** The version check (`wp --version`) and the
  activation invocation happen in two separate process spawns. A `wp` binary
  replaced on disk between the two would be executed. *Accepted because* the
  mitigation belongs to the consumer — do not make the `wp` binary writable by
  less-trusted users — and the attack requires local-filesystem write access,
  which is itself out of scope (see the local-filesystem entry below).

- **Locale-spoofed WP-CLI output.** The "activated N plugin(s)" summary is
  parsed from English-language WP-CLI output. Under a non-English WP-CLI locale
  the summary line may be misreported. *Accepted because* activation itself is
  unaffected — only the human-readable summary is — so this is a
  reporting-fidelity issue, not a security boundary.

- **DoS via WP-CLI never returning.** There is no client-side timeout in
  v1.0.x. A `wp` invocation that hangs will hang the Composer install
  indefinitely. *Accepted because* the mitigation is consumer-side process
  supervision; a built-in timeout policy would have to guess a cutoff and would
  surprise legitimately long-running activations.

- **Secrets leaked into Composer `--verbose` logs.** When the consumer runs
  Composer with `--verbose` / `-vvv`, WP-CLI's stdout — which may contain
  database credentials or API keys emitted by custom plugins — is written to
  the Composer log. *Accepted because* the verbosity level is consumer-chosen
  and consumer-controlled; this package does not redact process output.

- **Local-filesystem write access as a prerequisite.** An attacker who can edit
  the consumer's `composer.json` has already compromised the host. *Accepted
  because* the config-validation regexes are deliberately defence-in-depth, not
  a primary trust boundary: once an attacker controls `composer.json`, they
  control far more than this package's input.

- **Null bytes via `ProcessExecutor::escape()`.** Composer's `escape()` wraps
  its input in POSIX single quotes and preserves any embedded NUL byte verbatim
  — unlike PHP 8's `escapeshellarg()`, which throws `ValueError` on a NUL. When
  the resulting command string reaches PHP's `proc_open` / Symfony's Process
  layer, the underlying `execve`-style call terminates C strings at the first
  NUL, so a `\0` would truncate or malform the command before the binary runs.
  *Accepted because* this is a property of the PHP/Composer runtime rather than
  a defect of this package, and the `wp-cli` / `wp-path` allowlist regex
  already rejects NUL at parse time — the practical residual risk is low. It is
  catalogued here as an implementation-detail caveat; it does not change the
  public-facing API contract.

- **`posix_getuid()` absent on some Windows PHP builds.** The `allow-root:
  "auto"` mode relies on `posix_getuid()` to detect that the current process is
  uid 0. On Windows PHP builds where the `posix` extension is unavailable, the
  function is missing and `WpCli::shouldAllowRoot()` falls back conservatively
  to **not** appending `--allow-root`. *Accepted because* the fallback is the
  safe direction; consumers on such builds who genuinely need root-equivalent
  behaviour must set `allow-root: true` explicitly. Like the NUL caveat above,
  this is an implementation detail that does not change the API contract.

## Disclosure

See [`SECURITY.md`](../SECURITY.md) for the report channel (private GitHub
Security Advisories, with an email fallback).

**SLA:**

- Triage: within **7 days** of report.
- Fix target — **Critical**: within **30 days**.
- Fix target — **Medium**: within **90 days**.

No bug bounty.
