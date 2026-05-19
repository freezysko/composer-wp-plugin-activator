<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\IO\IOInterface;

final class Config
{
    public const EXTRA_KEY = 'composer-wp-plugin-activator';

    public const ALLOW_ROOT_AUTO = 'auto';
    public const ALLOW_ROOT_ALWAYS = 'always';
    public const ALLOW_ROOT_NEVER = 'never';

    /**
     * Allowlist of characters permitted in `wp-cli` and `wp-path` values.
     * Rejects every shell metacharacter (`;`, `` ` ``, `$`, `|`, `&`, `(`, `)`,
     * `<`, `>`, whitespace, newline, null byte, etc.). Applied after trim.
     */
    private const VALID_PATH_REGEX = '/^[A-Za-z0-9_.\/\-]+$/';

    private string $wpCli = 'wp';
    private ?string $wpPath = null;
    /** @var 'all'|'composer'|list<string> */
    private string|array $plugins = 'composer';
    /** @var list<string> */
    private array $priority = [];
    private bool $skipWhenNotInstalled = true;
    private bool $verbose = false;
    private bool $failOnError = false;
    private string $allowRootMode = self::ALLOW_ROOT_AUTO;

    private function __construct() {}

    /**
     * @param array<mixed> $extra
     */
    public static function fromExtra(array $extra, IOInterface $io): self
    {
        $raw = $extra[self::EXTRA_KEY] ?? [];

        if (!\is_array($raw)) {
            self::warn($io, \sprintf('"extra.%s" must be an object', self::EXTRA_KEY));
            $raw = [];
        }

        $config = new self();
        $config->wpCli = self::parseWpCli($raw, $io);
        $config->wpPath = self::parseWpPath($raw, $io);
        $config->plugins = self::parsePlugins($raw, $io);
        $config->priority = self::parsePriority($raw, $io);
        if (\is_array($config->plugins) && $config->priority !== []) {
            self::warn(
                $io,
                '"priority" is ignored when "plugins" is an explicit array '
                . '— the array already controls activation order'
            );
            $config->priority = [];
        }
        $config->skipWhenNotInstalled = self::parseBool($raw, 'skip-when-wp-not-installed', true, $io);
        $config->verbose = self::parseBool($raw, 'verbose', false, $io);
        $config->failOnError = self::parseBool($raw, 'fail-on-error', false, $io);
        $config->allowRootMode = self::parseAllowRoot($raw, $io);

        return $config;
    }

    public function getWpCli(): string
    {
        return $this->wpCli;
    }

    public function getWpPath(): ?string
    {
        return $this->wpPath;
    }

    /**
     * @return 'all'|'composer'|list<string>
     */
    public function getPlugins(): string|array
    {
        return $this->plugins;
    }

    /**
     * @return list<string>
     */
    public function getPriority(): array
    {
        return $this->priority;
    }

    public function shouldSkipWhenNotInstalled(): bool
    {
        return $this->skipWhenNotInstalled;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    public function shouldFailOnError(): bool
    {
        return $this->failOnError;
    }

    public function getAllowRootMode(): string
    {
        return $this->allowRootMode;
    }

    /**
     * @param array<mixed> $raw
     */
    private static function parseWpCli(array $raw, IOInterface $io): string
    {
        if (!\array_key_exists('wp-cli', $raw)) {
            return 'wp';
        }

        $value = $raw['wp-cli'];
        if (!\is_string($value) || trim($value) === '') {
            self::warn($io, '"wp-cli" must be a non-empty string, using default ("wp")');

            return 'wp';
        }

        $trimmed = trim($value);
        if (str_starts_with($trimmed, '-')) {
            self::warn(
                $io,
                '"wp-cli" must not start with "-" (would be interpreted as an option flag); using default ("wp")'
            );

            return 'wp';
        }

        if (preg_match(self::VALID_PATH_REGEX, $trimmed) !== 1) {
            self::warn($io, \sprintf(
                '"wp-cli" value %s contains disallowed characters; using default ("wp"). '
                . 'Allowed: alphanumerics, "_", ".", "/", "-".',
                var_export($value, true)
            ));

            return 'wp';
        }

        return $trimmed;
    }

    /**
     * @param array<mixed> $raw
     */
    private static function parseWpPath(array $raw, IOInterface $io): ?string
    {
        if (!\array_key_exists('wp-path', $raw)) {
            return null;
        }

        $value = $raw['wp-path'];
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || trim($value) === '') {
            self::warn($io, '"wp-path" must be a non-empty string or null, using default (null)');

            return null;
        }

        $trimmed = trim($value);
        if (str_starts_with($trimmed, '-')) {
            self::warn(
                $io,
                '"wp-path" must not start with "-" (would be interpreted as a WP-CLI option); using default (null)'
            );

            return null;
        }

        if (preg_match(self::VALID_PATH_REGEX, $trimmed) !== 1) {
            self::warn($io, \sprintf(
                '"wp-path" value %s contains disallowed characters; using default (null). '
                . 'Allowed: alphanumerics, "_", ".", "/", "-".',
                var_export($value, true)
            ));

            return null;
        }

        return $trimmed;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return 'all'|'composer'|list<string>
     */
    private static function parsePlugins(array $raw, IOInterface $io): string|array
    {
        if (!\array_key_exists('plugins', $raw)) {
            return 'composer';
        }

        $value = $raw['plugins'];

        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'all' || $normalized === 'composer') {
                return $normalized;
            }

            self::warn(
                $io,
                '"plugins" string must be "all" or "composer"; skipping activation '
                . '— set a valid value to opt in'
            );

            return [];
        }

        if (\is_array($value)) {
            return self::parsePluginsArray($value, $io);
        }

        self::warn(
            $io,
            '"plugins" must be "all", "composer", or an array of slugs; '
            . 'skipping activation — set a valid value to opt in'
        );

        return [];
    }

    /**
     * Parse the array branch of "plugins": iterate string entries through
     * `parseSlug`, drop non-strings, warn-and-fail-closed on empty result.
     *
     * @param array<mixed> $value
     *
     * @return list<string>
     */
    private static function parsePluginsArray(array $value, IOInterface $io): array
    {
        $slugs = [];
        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                continue;
            }
            $slug = self::parseSlug($entry, $io);
            if ($slug !== null) {
                $slugs[] = $slug;
            }
        }

        if ($slugs === []) {
            self::warn(
                $io,
                '"plugins" array contained no valid slugs; skipping activation '
                . '— fix the entries to opt in'
            );

            return [];
        }

        return $slugs;
    }

    /**
     * Parse the optional "priority" list — slugs to activate before the
     * main `plugins` pass. Invalid types fail closed to an empty list
     * (consistent with `parsePlugins`); per-entry slug normalization
     * mirrors the explicit `plugins` array.
     *
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private static function parsePriority(array $raw, IOInterface $io): array
    {
        if (!\array_key_exists('priority', $raw)) {
            return [];
        }

        $value = $raw['priority'];

        if (!\is_array($value)) {
            self::warn(
                $io,
                '"priority" must be an array of plugin slugs; ignoring it'
            );

            return [];
        }

        $slugs = [];
        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                continue;
            }
            $slug = self::parseSlug($entry, $io, 'priority');
            if ($slug !== null) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param array<mixed> $raw
     */
    private static function parseBool(array $raw, string $key, bool $default, IOInterface $io): bool
    {
        if (!\array_key_exists($key, $raw)) {
            return $default;
        }

        $value = $raw[$key];
        if (!\is_bool($value)) {
            self::warn($io, \sprintf(
                '"%s" must be a boolean, using default (%s)',
                $key,
                $default ? 'true' : 'false'
            ));

            return $default;
        }

        return $value;
    }

    /**
     * Strict literal validation per C-d12: only the JSON-native boolean
     * literals `true` / `false` or the string `"auto"` are accepted. Stringy
     * aliases like `"yes"` / `"always"` are rejected with a warning so a
     * shell-metacharacter payload cannot ride in on a permissive string
     * branch.
     *
     * @param array<mixed> $raw
     */
    private static function parseAllowRoot(array $raw, IOInterface $io): string
    {
        if (!\array_key_exists('allow-root', $raw)) {
            return self::ALLOW_ROOT_AUTO;
        }

        $value = $raw['allow-root'];

        if ($value === true) {
            return self::ALLOW_ROOT_ALWAYS;
        }

        if ($value === false) {
            return self::ALLOW_ROOT_NEVER;
        }

        if ($value === 'auto') {
            return self::ALLOW_ROOT_AUTO;
        }

        self::warn($io, '"allow-root" must be true, false, or "auto", using default ("auto")');

        return self::ALLOW_ROOT_AUTO;
    }

    /**
     * Normalize and validate a single explicit plugin slug entry.
     *
     * If the entry contains "/", treat it as a Composer-style package name
     * and strip everything up to and including the last "/" — composer/installers
     * installs WP plugins to "{plugins}/<part-after-slash>", so the WP slug
     * is what follows the slash. The strip is announced with a warning so
     * the operator knows the config was transformed.
     *
     * Invalid input produces a single warning and returns null. If a strip
     * was attempted but the result is still invalid, only the "not a valid
     * slug" warning is emitted — the redundant "normalized" warning is
     * suppressed.
     */
    private static function parseSlug(string $raw, IOInterface $io, string $fieldLabel = 'plugins'): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $candidate = $value;
        $wasStripped = false;
        if (str_contains($value, '/')) {
            $candidate = substr($value, (int) strrpos($value, '/') + 1);
            $wasStripped = true;
        }

        if (!self::isValidSlug($candidate)) {
            self::warn($io, \sprintf(
                '"%s" entry "%s" is not a valid plugin slug, skipping',
                $fieldLabel,
                $value
            ));

            return null;
        }

        if ($wasStripped) {
            self::warn($io, \sprintf(
                '"%s" entry "%s" looks like a Composer package name; using "%s" as the slug '
                . '— write the WP plugin slug directly to avoid this warning',
                $fieldLabel,
                $value,
                $candidate
            ));
        }

        return $candidate;
    }

    /**
     * Validates a plugin slug against a conservative whitelist:
     * `[a-zA-Z0-9][a-zA-Z0-9_.-]*`. Rejects leading "-" (WP-CLI would
     * treat it as an option), leading "." (hidden-file weirdness), and
     * whitespace / shell metacharacters. The wordpress.org slug spec is
     * stricter (lowercase `[a-z0-9-]` only); this is intentionally more
     * permissive to accommodate custom-plugin directory names.
     *
     * Shared between user-supplied (`Config`) and Composer-derived
     * (`ComposerPluginResolver`) slug paths so both apply the same rule.
     */
    public static function isValidSlug(string $candidate): bool
    {
        return $candidate !== ''
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $candidate) === 1;
    }

    private static function warn(IOInterface $io, string $message): void
    {
        $io->writeError('<warning>composer-wp-plugin-activator: ' . $message . '</warning>');
    }
}
