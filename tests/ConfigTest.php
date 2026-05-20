<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\IO\IOInterface;
use Freezysko\ComposerWpPluginActivator\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function io(): IOInterface
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('writeError');

        return $io;
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function fromRaw(array $pluginConfig): Config
    {
        return Config::fromExtra(
            [Config::EXTRA_KEY => $pluginConfig],
            $this->io()
        );
    }

    public function testDefaultsWhenExtraKeyMissing(): void
    {
        $config = Config::fromExtra([], $this->io());

        self::assertSame('wp', $config->getWpCli());
        self::assertNull($config->getWpPath());
        self::assertSame('composer', $config->getPlugins());
        self::assertTrue($config->shouldSkipWhenNotInstalled());
        self::assertFalse($config->isVerbose());
        self::assertFalse($config->shouldFailOnError());
        self::assertSame(Config::ALLOW_ROOT_AUTO, $config->getAllowRootMode());
    }

    public function testReadsValidValues(): void
    {
        $config = $this->fromRaw([
            'wp-cli' => '/usr/local/bin/wp',
            'wp-path' => '/srv/site/web/wp',
            'plugins' => ['woocommerce', 'polylang'],
            'skip-when-wp-not-installed' => false,
            'verbose' => true,
            'fail-on-error' => true,
            'allow-root' => false,
        ]);

        self::assertSame('/usr/local/bin/wp', $config->getWpCli());
        self::assertSame('/srv/site/web/wp', $config->getWpPath());
        self::assertSame(['woocommerce', 'polylang'], $config->getPlugins());
        self::assertFalse($config->shouldSkipWhenNotInstalled());
        self::assertTrue($config->isVerbose());
        self::assertTrue($config->shouldFailOnError());
        self::assertSame(Config::ALLOW_ROOT_NEVER, $config->getAllowRootMode());
    }

    public function testPluginsAcceptsAllAndComposerStrings(): void
    {
        self::assertSame('all', $this->fromRaw(['plugins' => 'all'])->getPlugins());
        self::assertSame('composer', $this->fromRaw(['plugins' => 'composer'])->getPlugins());
        self::assertSame('composer', $this->fromRaw(['plugins' => ' COMPOSER '])->getPlugins());
    }

    public function testPluginsArrayIsTrimmedAndFilteredOfEmptyEntries(): void
    {
        $config = $this->fromRaw(['plugins' => ['  woocommerce  ', '', 'polylang']]);

        self::assertSame(['woocommerce', 'polylang'], $config->getPlugins());
    }

    /**
     * @dataProvider allowRootAcceptedLiteralProvider
     */
    public function testAllowRootAcceptsLiteral(mixed $input, string $expected): void
    {
        self::assertSame($expected, $this->fromRaw(['allow-root' => $input])->getAllowRootMode());
    }

    /**
     * Per C-d12: only the JSON-native booleans `true`/`false` and the string
     * `"auto"` are accepted. Stringy aliases ("yes", "always", "1", "TRUE"…)
     * are rejected with a warning so a shell-metacharacter payload cannot
     * ride in on a permissive string branch.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function allowRootAcceptedLiteralProvider(): array
    {
        return [
            'bool true' => [true, Config::ALLOW_ROOT_ALWAYS],
            'bool false' => [false, Config::ALLOW_ROOT_NEVER],
            'string auto' => ['auto', Config::ALLOW_ROOT_AUTO],
        ];
    }

    /**
     * @dataProvider allowRootRejectedProvider
     */
    public function testAllowRootRejectsNonLiteralWithWarning(mixed $input): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('allow-root'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['allow-root' => $input]], $io);

        self::assertSame(Config::ALLOW_ROOT_AUTO, $config->getAllowRootMode());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function allowRootRejectedProvider(): array
    {
        return [
            'string true' => ['true'],
            'string false' => ['false'],
            'string always' => ['always'],
            'string never' => ['never'],
            'string yes' => ['yes'],
            'string no' => ['no'],
            'string 1' => ['1'],
            'string 0' => ['0'],
            'mixed case TRUE' => ['  TRUE  '],
        ];
    }

    /**
     * Capture every writeError() message into a list for full-text assertions.
     *
     * @param list<string> $sink
     */
    private function capturingIo(array &$sink): IOInterface
    {
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (string $message) use (&$sink): void {
            $sink[] = $message;
        });

        return $io;
    }

    public function testInvalidWpCliWarnsAndFallsBackToDefault(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-cli'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-cli' => 123]], $io);

        self::assertSame('wp', $config->getWpCli());
    }

    public function testWarningMessagesAreWrappedInTheWarningTagAndPrefix(): void
    {
        // Config::warn() wraps every message as
        // `<warning>composer-wp-plugin-activator: <msg></warning>`.
        // Asserting the exact prefix AND suffix kills the Concat /
        // ConcatOperandRemoval mutants on that wrapper.
        $writes = [];
        $io = $this->capturingIo($writes);

        Config::fromExtra([Config::EXTRA_KEY => ['wp-cli' => 123]], $io);

        self::assertNotEmpty($writes);
        foreach ($writes as $message) {
            self::assertStringStartsWith('<warning>composer-wp-plugin-activator: ', $message);
            self::assertStringEndsWith('</warning>', $message);
        }
    }

    public function testInvalidWpCliWarningIsTheExactDisallowedCharsMessage(): void
    {
        // Asserting the exact, fully-assembled warning kills Concat (operand
        // reorder) as well as ConcatOperandRemoval on the message string.
        $writes = [];
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['wp-cli' => 'bad;value']],
            $this->capturingIo($writes)
        );

        self::assertSame('wp', $config->getWpCli());
        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"wp-cli" value \'bad;value\' contains disallowed characters; using default ("wp"). '
            . 'Allowed: alphanumerics, "_", ".", "/", "-".</warning>',
            $writes
        );
    }

    public function testInvalidWpPathWarningIsTheExactDisallowedCharsMessage(): void
    {
        $writes = [];
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['wp-path' => 'bad;value']],
            $this->capturingIo($writes)
        );

        self::assertNull($config->getWpPath());
        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"wp-path" value \'bad;value\' contains disallowed characters; using default (null). '
            . 'Allowed: alphanumerics, "_", ".", "/", "-".</warning>',
            $writes
        );
    }

    public function testInvalidPluginsStringWarningIsTheExactMessage(): void
    {
        $writes = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => 'everything']],
            $this->capturingIo($writes)
        );

        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"plugins" string must be "all" or "composer"; skipping activation '
            . '— set a valid value to opt in</warning>',
            $writes
        );
    }

    public function testInvalidPluginsTypeWarningIsTheExactMessage(): void
    {
        $writes = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => 42]],
            $this->capturingIo($writes)
        );

        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"plugins" must be "all", "composer", or an array of slugs; '
            . 'skipping activation — set a valid value to opt in</warning>',
            $writes
        );
    }

    public function testEmptyPluginsArrayWarningIsTheExactMessage(): void
    {
        $writes = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => ['', '  ']]],
            $this->capturingIo($writes)
        );

        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"plugins" array contained no valid slugs; skipping activation '
            . '— fix the entries to opt in</warning>',
            $writes
        );
    }

    public function testComposerPackageNameWarningIsTheExactMessage(): void
    {
        $writes = [];
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => ['wpackagist-plugin/woocommerce']]],
            $this->capturingIo($writes)
        );

        self::assertSame(['woocommerce'], $config->getPlugins());
        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"plugins" entry "wpackagist-plugin/woocommerce" looks like a Composer package name; '
            . 'using "woocommerce" as the slug '
            . '— write the WP plugin slug directly to avoid this warning</warning>',
            $writes
        );
    }

    public function testPriorityIgnoredWarningIsTheExactMessage(): void
    {
        $writes = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => [
                'plugins' => ['woocommerce'],
                'priority' => ['polylang'],
            ]],
            $this->capturingIo($writes)
        );

        self::assertContains(
            '<warning>composer-wp-plugin-activator: '
            . '"priority" is ignored when "plugins" is an explicit array '
            . '— the array already controls activation order</warning>',
            $writes
        );
    }

    public function testInvalidPluginsWarnsAndFallsBackToEmptyList(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('plugins'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['plugins' => 42]], $io);

        self::assertSame([], $config->getPlugins());
    }

    public function testInvalidPluginsStringWarnsAndFallsBackToEmptyList(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra([Config::EXTRA_KEY => ['plugins' => 'everything']], $io);

        self::assertSame([], $config->getPlugins());
    }

    public function testEmptyPluginsArrayWarnsAndFallsBackToEmptyList(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra([Config::EXTRA_KEY => ['plugins' => ['', '  ']]], $io);

        self::assertSame([], $config->getPlugins());
    }

    public function testInvalidBoolWarnsAndFallsBackToDefault(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra([Config::EXTRA_KEY => ['verbose' => 'yes please']], $io);

        self::assertFalse($config->isVerbose());
    }

    public function testInvalidBoolWarningNamesTheActualDefaultValue(): void
    {
        // `verbose` defaults to false, `skip-when-wp-not-installed` to true.
        // The warning interpolates the real default via `$default ? 'true'
        // : 'false'`; asserting the exact word kills the Ternary mutant that
        // swaps the two arms.
        $verboseWrites = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => ['verbose' => 'not-a-bool']],
            $this->capturingIo($verboseWrites)
        );
        self::assertStringContainsString(
            'using default (false)',
            implode("\n", $verboseWrites)
        );

        $skipWrites = [];
        Config::fromExtra(
            [Config::EXTRA_KEY => ['skip-when-wp-not-installed' => 'not-a-bool']],
            $this->capturingIo($skipWrites)
        );
        self::assertStringContainsString(
            'using default (true)',
            implode("\n", $skipWrites)
        );
    }

    public function testWhitespaceOnlyWpCliIsRejectedAsEmptyNotAsDisallowedChars(): void
    {
        // A whitespace-only value must be caught by the `trim($value) === ''`
        // empty check, NOT fall through to the disallowed-characters branch.
        // The UnwrapTrim mutant (`trim($value) === ''` → `$value === ''`)
        // would route "   " to the wrong warning.
        $writes = [];
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['wp-cli' => '   ']],
            $this->capturingIo($writes)
        );

        self::assertSame('wp', $config->getWpCli());
        $joined = implode("\n", $writes);
        self::assertStringContainsString('"wp-cli" must be a non-empty string', $joined);
        self::assertStringNotContainsString('disallowed characters', $joined);
    }

    public function testWhitespaceOnlyWpPathIsRejectedAsEmptyNotAsDisallowedChars(): void
    {
        $writes = [];
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['wp-path' => "  \t "]],
            $this->capturingIo($writes)
        );

        self::assertNull($config->getWpPath());
        $joined = implode("\n", $writes);
        self::assertStringContainsString('"wp-path" must be a non-empty string or null', $joined);
        self::assertStringNotContainsString('disallowed characters', $joined);
    }

    public function testPluginsArraySkipsNonStringEntriesAndKeepsLaterValidOnes(): void
    {
        // A non-string entry ordered BEFORE a valid string slug: parsing must
        // `continue` past the non-string and still collect the later slug.
        // A `break` mutation would abandon the loop and drop "polylang".
        $config = $this->fromRaw(['plugins' => ['woocommerce', 42, 'polylang']]);

        self::assertSame(['woocommerce', 'polylang'], $config->getPlugins());
    }

    public function testPriorityArraySkipsNonStringEntriesAndKeepsLaterValidOnes(): void
    {
        // Same ordering check for the `priority` array branch.
        $config = $this->fromRaw(['priority' => ['woocommerce', false, 'polylang']]);

        self::assertSame(['woocommerce', 'polylang'], $config->getPriority());
    }

    public function testInvalidAllowRootWarnsAndFallsBackToAuto(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra([Config::EXTRA_KEY => ['allow-root' => 'sometimes']], $io);

        self::assertSame(Config::ALLOW_ROOT_AUTO, $config->getAllowRootMode());
    }

    public function testNonArrayExtraSectionWarnsAndUsesDefaults(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra([Config::EXTRA_KEY => 'not-an-object'], $io);

        self::assertSame('wp', $config->getWpCli());
    }

    public function testWpPathAcceptsExplicitNull(): void
    {
        self::assertNull($this->fromRaw(['wp-path' => null])->getWpPath());
    }

    public function testInvalidWpPathWarnsAndFallsBackToNull(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-path'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-path' => 123]], $io);

        self::assertNull($config->getWpPath());
    }

    public function testWpCliAndWpPathAreTrimmed(): void
    {
        $config = $this->fromRaw([
            'wp-cli' => '  /usr/local/bin/wp  ',
            'wp-path' => "  /srv/site/web/wp\n",
        ]);

        self::assertSame('/usr/local/bin/wp', $config->getWpCli());
        self::assertSame('/srv/site/web/wp', $config->getWpPath());
    }

    public function testPluginsArrayStripsComposerVendorPrefix(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('looks like a Composer package name'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => ['wpackagist-plugin/woocommerce', 'wp-plugin/foo']]],
            $io
        );

        self::assertSame(['woocommerce', 'foo'], $config->getPlugins());
    }

    public function testPluginsArrayRejectsInvalidSlugsAndFallsBackToEmptyList(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => ['-bad', 'foo bar', '.hidden']]],
            $io
        );

        self::assertSame([], $config->getPlugins());
    }

    public function testPluginsArrayKeepsValidEntriesAndSkipsInvalid(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('-bad'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => ['woocommerce', '-bad', 'polylang']]],
            $io
        );

        self::assertSame(['woocommerce', 'polylang'], $config->getPlugins());
    }

    public function testPluginsArrayAcceptsLegitimateCustomSlugs(): void
    {
        $config = $this->fromRaw([
            'plugins' => ['client_custom', 'foo.bar', 'My-Plugin', 'plugin2'],
        ]);

        self::assertSame(['client_custom', 'foo.bar', 'My-Plugin', 'plugin2'], $config->getPlugins());
    }

    /**
     * @dataProvider validSlugProvider
     */
    public function testIsValidSlugAcceptsLegitimateSlugs(string $slug): void
    {
        self::assertTrue(Config::isValidSlug($slug));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validSlugProvider(): array
    {
        return [
            'lowercase' => ['woocommerce'],
            'with hyphen' => ['woo-variation-swatches'],
            'with underscore' => ['client_custom'],
            'with dot' => ['foo.bar'],
            'mixed case' => ['My-Plugin'],
            'with digit' => ['plugin2'],
            'starts with digit' => ['2fa'],
        ];
    }

    /**
     * @dataProvider invalidSlugProvider
     */
    public function testIsValidSlugRejectsBadSlugs(string $slug): void
    {
        self::assertFalse(Config::isValidSlug($slug));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSlugProvider(): array
    {
        return [
            'empty' => [''],
            'leading hyphen' => ['-bad'],
            'leading dot' => ['.hidden'],
            'leading underscore' => ['_foo'],
            'with whitespace' => ['foo bar'],
            'with slash' => ['vendor/name'],
            'with semicolon' => ['rm;ls'],
            'with shell metachar' => ['$(echo)'],
        ];
    }

    public function testPriorityDefaultsToEmptyArray(): void
    {
        self::assertSame([], $this->fromRaw([])->getPriority());
    }

    public function testPriorityAcceptsValidSlugList(): void
    {
        $config = $this->fromRaw(['priority' => ['woocommerce', 'polylang']]);

        self::assertSame(['woocommerce', 'polylang'], $config->getPriority());
    }

    public function testPriorityStripsComposerVendorPrefix(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('looks like a Composer package name'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['priority' => ['wpackagist-plugin/woocommerce']]],
            $io
        );

        self::assertSame(['woocommerce'], $config->getPriority());
    }

    public function testPriorityRejectsInvalidSlugsWithWarning(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('"priority" entry "-bad"'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['priority' => ['-bad', 'woocommerce']]],
            $io
        );

        self::assertSame(['woocommerce'], $config->getPriority());
    }

    public function testInvalidPriorityTypeFallsBackToEmpty(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('"priority" must be an array'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['priority' => 'woocommerce']],
            $io
        );

        self::assertSame([], $config->getPriority());
    }

    public function testPriorityIgnoredWhenPluginsIsExplicitArray(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('"priority" is ignored'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => [
                'plugins' => ['woocommerce', 'polylang'],
                'priority' => ['woocommerce'],
            ]],
            $io
        );

        self::assertSame(['woocommerce', 'polylang'], $config->getPlugins());
        self::assertSame([], $config->getPriority());
    }

    public function testPluginsArrayStripThenInvalidIsRejectedWithSingleWarning(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::exactly(2))
            ->method('writeError')
            ->with(self::logicalOr(
                self::stringContains('not a valid plugin slug'),
                self::stringContains('looks like a Composer package name')
            ));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => [
                'plugins' => ['wpackagist-plugin/-bad', 'wpackagist-plugin/woocommerce'],
            ]],
            $io
        );

        self::assertSame(['woocommerce'], $config->getPlugins());
    }

    // ---------------------------------------------------------------------
    // Injection-payload tests (C-6 / C-d19 / spec § 5.7)
    //
    // Every consumer-supplied key that lands on the WP-CLI argv must reject
    // shell metacharacters at parse time. The shared provider below pins the
    // behaviour so a regex change cannot silently regress.
    // ---------------------------------------------------------------------

    /**
     * Representative shell-metacharacter payloads. Each must be rejected by
     * every key-level validator that feeds the WP-CLI argv.
     *
     * @return array<string, array{string}>
     */
    public static function shellMetaPayloadsProvider(): array
    {
        return [
            'semicolon' => ['; ls'],
            'backtick' => ['`id`'],
            'command-sub' => ['$(whoami)'],
            'pipe-or' => ['|| true'],
            'pipe-and' => ['&& whoami'],
            'newline' => ["wp\nls"],
            'null-byte' => ["wp\0ls"],
            'destructive' => ['wp; rm -rf /'],
        ];
    }

    /**
     * @dataProvider shellMetaPayloadsProvider
     */
    public function testWpCliRejectsShellMetacharacters(string $payload): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-cli'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-cli' => $payload]], $io);

        self::assertSame('wp', $config->getWpCli());
    }

    /**
     * @dataProvider shellMetaPayloadsProvider
     */
    public function testWpPathRejectsShellMetacharacters(string $payload): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-path'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-path' => $payload]], $io);

        self::assertNull($config->getWpPath());
    }

    public function testWpPathRejectsLeadingDashOptionSpoofing(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-path'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-path' => '-version']], $io);

        self::assertNull($config->getWpPath());
    }

    public function testWpCliRejectsLeadingDashOptionSpoofing(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-cli'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['wp-cli' => '-version']], $io);

        self::assertSame('wp', $config->getWpCli());
    }

    /**
     * @dataProvider shellMetaPayloadsProvider
     */
    public function testAllowRootRejectsShellMetacharacters(string $payload): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('allow-root'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['allow-root' => $payload]], $io);

        self::assertSame(Config::ALLOW_ROOT_AUTO, $config->getAllowRootMode());
    }

    /**
     * @dataProvider shellMetaPayloadsProvider
     */
    public function testPluginsEntryRejectsShellMetacharacters(string $payload): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['plugins' => [$payload]]],
            $io
        );

        self::assertSame([], $config->getPlugins());
    }

    /**
     * @dataProvider shellMetaPayloadsProvider
     */
    public function testPriorityEntryRejectsShellMetacharacters(string $payload): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['priority' => [$payload]]],
            $io
        );

        self::assertSame([], $config->getPriority());
    }

    public function testVerboseAcceptsTrueBool(): void
    {
        // Positive control: a valid bool flips the value from default (false)
        // to true. Without this, "rejection" tests below could pass merely
        // because the default also happens to be false.
        $config = $this->fromRaw(['verbose' => true]);

        self::assertTrue($config->isVerbose());
    }

    public function testVerboseRejectsStringTrue(): void
    {
        // String "true" is rejected by strict is_bool → falls back to
        // default (false) AND emits a warning. Asserting both makes this
        // distinct from the "default happens to be false" trivial case.
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('verbose'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['verbose' => 'true']], $io);

        self::assertFalse($config->isVerbose());
    }

    public function testFailOnErrorRejectsStringTrue(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('fail-on-error'));

        $config = Config::fromExtra([Config::EXTRA_KEY => ['fail-on-error' => 'true']], $io);

        self::assertFalse($config->shouldFailOnError());
    }

    public function testSkipWhenWpNotInstalledRejectsStringFalse(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('skip-when-wp-not-installed'));

        $config = Config::fromExtra(
            [Config::EXTRA_KEY => ['skip-when-wp-not-installed' => 'false']],
            $io
        );

        // Default is true; string "false" is rejected → stays true.
        self::assertTrue($config->shouldSkipWhenNotInstalled());
    }
}
