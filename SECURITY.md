# Security Policy

## Supported versions

The project has a single release line: the latest `1.0.x` release receives
security fixes.

## Reporting a vulnerability

Use **GitHub Security Advisories** (Private vulnerability reporting) for this
repository:
https://github.com/freezysko/composer-wp-plugin-activator/security/advisories/new

If GitHub is unavailable to you, email `marek.viger@gmail.com`. Encrypt with
the maintainer's SSH/PGP signing key if you have it; otherwise plain email is
acceptable.

## Threat model

See [`.github/SECURITY-THREAT-MODEL.md`](.github/SECURITY-THREAT-MODEL.md) for
the explicit list of in-scope and out-of-scope attack classes for v1.0.x.

Out-of-scope risks are listed, with rationale, in the threat model's
[Out-of-scope section](.github/SECURITY-THREAT-MODEL.md#out-of-scope-known-limitations).

## Triage and disclosure

- Triage: within 7 days of report.
- Fix target:
  - Critical: within 30 days.
  - Medium: within 90 days.
- Coordinated disclosure: requesters and maintainer agree on a public-release
  date when a patched release ships.
