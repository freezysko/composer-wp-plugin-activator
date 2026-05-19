<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\IO\IOInterface;
use Freezysko\ComposerWpPluginActivator\Activator;
use Freezysko\ComposerWpPluginActivator\Config;
use Freezysko\ComposerWpPluginActivator\WpCli;
use Freezysko\ComposerWpPluginActivator\WpCliResult;
use PHPUnit\Framework\TestCase;

final class ActivatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function config(array $pluginConfig = []): Config
    {
        return Config::fromExtra(
            [Config::EXTRA_KEY => $pluginConfig],
            $this->createMock(IOInterface::class)
        );
    }

    /**
     * @param list<string> $slugs
     *
     * @return callable(): list<string>
     */
    private function resolver(array $slugs = []): callable
    {
        return static fn(): array => $slugs;
    }

    public function testSkipsWhenBinaryMissing(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(false);
        $wpCli->expects(self::never())->method('isWordPressInstalled');
        $wpCli->expects(self::never())->method('activateAll');

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('wp-cli not found'));

        $activator = new Activator($this->config(), $wpCli, $io);

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testSkipsWhenWordPressNotInstalledAndSkipEnabled(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(false);
        $wpCli->expects(self::never())->method('activateAll');
        $wpCli->expects(self::never())->method('activate');

        $activator = new Activator(
            $this->config(['skip-when-wp-not-installed' => true]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testProceedsWhenWordPressNotInstalledAndSkipDisabled(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->expects(self::never())->method('isWordPressInstalled');
        $wpCli->expects(self::once())
            ->method('activateAll')
            ->willReturn(new WpCliResult(0, "Plugin 'hello' activated."));

        $activator = new Activator(
            $this->config(['skip-when-wp-not-installed' => false, 'plugins' => 'all']),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testAllModeCallsActivateAll(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::once())
            ->method('activateAll')
            ->willReturn(new WpCliResult(0, "Plugin 'hello' activated."));

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $this->createMock(IOInterface::class));

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testComposerModeActivatesResolvedSlugs(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::once())
            ->method('activate')
            ->with(['woocommerce', 'polylang'])
            ->willReturn(new WpCliResult(0, "Plugin 'woocommerce' activated.\nPlugin 'polylang' activated."));

        $activator = new Activator(
            $this->config(['plugins' => 'composer']),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver(['woocommerce', 'polylang'])));
    }

    public function testComposerModeWithNoResolvedPluginsSkips(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::never())->method('activate');
        $wpCli->expects(self::never())->method('activateAll');

        $activator = new Activator(
            $this->config(['plugins' => 'composer']),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver([])));
    }

    public function testExplicitListModeActivatesGivenSlugsInOrder(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::once())
            ->method('activate')
            ->with(['woocommerce', 'woo-variation-swatches'])
            ->willReturn(new WpCliResult(0, "Plugin 'woocommerce' activated."));

        $activator = new Activator(
            $this->config(['plugins' => ['woocommerce', 'woo-variation-swatches']]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testReturnsNonZeroExitCodeWhenWpCliFails(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activateAll')->willReturn(new WpCliResult(1, 'Error: something went wrong'));

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(1, $activator->run($this->resolver()));
    }

    public function testReportsAlreadyActiveWhenNothingWasActivated(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activateAll')->willReturn(
            new WpCliResult(0, "Warning: Plugin 'hello' is already active.")
        );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('already active'));

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testReportsActivatedCountWhenPluginsWereActivated(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activateAll')->willReturn(
            new WpCliResult(0, "Plugin 'woocommerce' activated.\nPlugin 'polylang' activated.")
        );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('activated 2 plugin'));

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testVerboseModePrintsFullWpCliOutput(): void
    {
        $fullOutput = "Plugin 'woocommerce' activated.\nSuccess: Activated 1 of 1 plugins.";

        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activateAll')->willReturn(new WpCliResult(0, $fullOutput));

        $writes = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('write')->willReturnCallback(static function (string $message) use (&$writes): void {
            $writes[] = $message;
        });

        $activator = new Activator($this->config(['verbose' => true, 'plugins' => 'all']), $wpCli, $io);

        self::assertSame(0, $activator->run($this->resolver()));
        self::assertContains($fullOutput, $writes);
    }

    public function testRetriesWhenPassMakesProgressThenSucceeds(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::exactly(2))
            ->method('activateAll')
            ->willReturnOnConsecutiveCalls(
                new WpCliResult(1, "Plugin 'woocommerce' activated.\nError: Plugin 'woo-variation-swatches' could not be activated."),
                new WpCliResult(0, "Plugin 'woo-variation-swatches' activated.")
            );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('activated 2 plugin'));

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testStopsRetryingWhenPassMakesNoProgress(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::exactly(2))
            ->method('activateAll')
            ->willReturnOnConsecutiveCalls(
                new WpCliResult(1, "Plugin 'woocommerce' activated.\nError: Plugin 'needs-missing' could not be activated."),
                new WpCliResult(1, "Error: Plugin 'needs-missing' could not be activated.")
            );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(1, $activator->run($this->resolver()));
    }

    public function testStopsAtMaxAttemptsWhenEveryPassMakesProgress(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::exactly(Activator::MAX_ACTIVATION_ATTEMPTS))
            ->method('activateAll')
            ->willReturn(new WpCliResult(1, "Plugin 'x' activated.\nError: more plugins remain."));

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(1, $activator->run($this->resolver()));
    }

    public function testRetryAppliesToExplicitListMode(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::exactly(2))
            ->method('activate')
            ->with(['woocommerce', 'woo-variation-swatches'])
            ->willReturnOnConsecutiveCalls(
                new WpCliResult(1, "Plugin 'woocommerce' activated.\nError: Plugin 'woo-variation-swatches' could not be activated."),
                new WpCliResult(0, "Plugin 'woo-variation-swatches' activated.")
            );

        $activator = new Activator(
            $this->config(['plugins' => ['woocommerce', 'woo-variation-swatches']]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testRetryAppliesToComposerMode(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::exactly(2))
            ->method('activate')
            ->with(['woocommerce', 'woo-variation-swatches'])
            ->willReturnOnConsecutiveCalls(
                new WpCliResult(1, "Plugin 'woocommerce' activated.\nError: Plugin 'woo-variation-swatches' could not be activated."),
                new WpCliResult(0, "Plugin 'woo-variation-swatches' activated.")
            );

        $activator = new Activator(
            $this->config(['plugins' => 'composer']),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver(['woocommerce', 'woo-variation-swatches'])));
    }

    public function testEmptyExplicitListSkipsActivation(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::never())->method('activate');
        $wpCli->expects(self::never())->method('activateAll');

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('nothing to activate'));

        // Invalid `plugins` value falls back to [] (fail-closed); Activator
        // must NOT broaden to all/composer in this case.
        $activator = new Activator(
            $this->config(['plugins' => 'everything']),
            $wpCli,
            $io
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testPriorityListActivatedBeforeAllMode(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);

        $callOrder = [];
        $wpCli->expects(self::once())
            ->method('activate')
            ->with(['woocommerce'])
            ->willReturnCallback(static function (array $slugs) use (&$callOrder): WpCliResult {
                $callOrder[] = 'priority';

                return new WpCliResult(0, "Plugin 'woocommerce' activated.");
            });
        $wpCli->expects(self::once())
            ->method('activateAll')
            ->willReturnCallback(static function () use (&$callOrder): WpCliResult {
                $callOrder[] = 'all';

                return new WpCliResult(0, "Plugin 'polylang' activated.");
            });

        $activator = new Activator(
            $this->config(['plugins' => 'all', 'priority' => ['woocommerce']]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver()));
        self::assertSame(['priority', 'all'], $callOrder);
    }

    public function testPriorityListActivatedBeforeComposerMode(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);

        $observedArgs = [];
        $wpCli->expects(self::exactly(2))
            ->method('activate')
            ->willReturnCallback(static function (array $slugs) use (&$observedArgs): WpCliResult {
                $observedArgs[] = $slugs;

                return new WpCliResult(0, "Plugin 'x' activated.");
            });

        $activator = new Activator(
            $this->config(['plugins' => 'composer', 'priority' => ['woocommerce']]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        self::assertSame(0, $activator->run($this->resolver(['woo-variation-swatches'])));
        self::assertSame(
            [['woocommerce'], ['woo-variation-swatches']],
            $observedArgs
        );
    }

    public function testPriorityCountsTowardActivatedTotal(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activate')->willReturn(new WpCliResult(0, "Plugin 'woocommerce' activated."));
        $wpCli->method('activateAll')->willReturn(new WpCliResult(0, "Plugin 'polylang' activated."));

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('activated 2 plugin'));

        $activator = new Activator(
            $this->config(['plugins' => 'all', 'priority' => ['woocommerce']]),
            $wpCli,
            $io
        );

        self::assertSame(0, $activator->run($this->resolver()));
    }

    public function testPriorityFailurePreservesNonZeroExitCodeEvenWhenMainSucceeds(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        // Priority pass fails — wp-cli reported "could not be activated".
        $wpCli->expects(self::once())
            ->method('activate')
            ->with(['woocommerce'])
            ->willReturn(new WpCliResult(1, "Error: Plugin 'woocommerce' could not be activated."));
        // Main pass still runs and succeeds — convergence behavior unchanged.
        $wpCli->expects(self::once())
            ->method('activateAll')
            ->willReturn(new WpCliResult(0, "Plugin 'polylang' activated."));

        $activator = new Activator(
            $this->config(['plugins' => 'all', 'priority' => ['woocommerce']]),
            $wpCli,
            $this->createMock(IOInterface::class)
        );

        // Final exit code reflects the priority failure so fail-on-error
        // semantics still apply at the Plugin layer.
        self::assertSame(1, $activator->run($this->resolver()));
    }

    public function testPriorityFailureOutputIsSurfacedEvenWithoutVerbose(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activate')
            ->with(['woocommerce'])
            ->willReturn(new WpCliResult(1, "Error: Plugin 'woocommerce' could not be activated."));
        $wpCli->method('activateAll')
            ->willReturn(new WpCliResult(0, "Plugin 'polylang' activated."));

        $errorWrites = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (string $message) use (&$errorWrites): void {
            $errorWrites[] = $message;
        });

        // verbose intentionally NOT set — priority failure must still surface.
        $activator = new Activator(
            $this->config(['plugins' => 'all', 'priority' => ['woocommerce']]),
            $wpCli,
            $io
        );

        $activator->run($this->resolver());

        $joined = implode("\n", $errorWrites);
        self::assertStringContainsString('priority pass failed', $joined);
        self::assertStringContainsString("Plugin 'woocommerce' could not be activated", $joined);
    }

    public function testPriorityOnlyRunWhenComposerResolutionIsEmpty(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->expects(self::once())
            ->method('activate')
            ->with(['woocommerce'])
            ->willReturn(new WpCliResult(0, "Plugin 'woocommerce' activated."));
        $wpCli->expects(self::never())->method('activateAll');

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('write')
            ->with(self::stringContains('activated 1 plugin'));

        $activator = new Activator(
            $this->config(['plugins' => 'composer', 'priority' => ['woocommerce']]),
            $wpCli,
            $io
        );

        self::assertSame(0, $activator->run($this->resolver([])));
    }

    public function testReportFailureWithEmptyOutputDoesNotWriteBlankLine(): void
    {
        $wpCli = $this->createMock(WpCli::class);
        $wpCli->method('binaryExists')->willReturn(true);
        $wpCli->method('isWordPressInstalled')->willReturn(true);
        $wpCli->method('activateAll')->willReturn(new WpCliResult(1, ''));

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::once())->method('writeError');

        $activator = new Activator($this->config(['plugins' => 'all']), $wpCli, $io);

        self::assertSame(1, $activator->run($this->resolver()));
    }
}
