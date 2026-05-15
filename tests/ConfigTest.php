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
     * @dataProvider allowRootProvider
     */
    public function testAllowRootNormalization(mixed $input, string $expected): void
    {
        self::assertSame($expected, $this->fromRaw(['allow-root' => $input])->getAllowRootMode());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function allowRootProvider(): array
    {
        return [
            'bool true' => [true, Config::ALLOW_ROOT_ALWAYS],
            'bool false' => [false, Config::ALLOW_ROOT_NEVER],
            'string auto' => ['auto', Config::ALLOW_ROOT_AUTO],
            'string true' => ['true', Config::ALLOW_ROOT_ALWAYS],
            'string false' => ['false', Config::ALLOW_ROOT_NEVER],
            'string always' => ['always', Config::ALLOW_ROOT_ALWAYS],
            'string never' => ['never', Config::ALLOW_ROOT_NEVER],
            'string yes' => ['yes', Config::ALLOW_ROOT_ALWAYS],
            'string no' => ['no', Config::ALLOW_ROOT_NEVER],
            'string 1' => ['1', Config::ALLOW_ROOT_ALWAYS],
            'string 0' => ['0', Config::ALLOW_ROOT_NEVER],
            'mixed case' => ['  TRUE  ', Config::ALLOW_ROOT_ALWAYS],
        ];
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
}
