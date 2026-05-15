<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Freezysko\ComposerWpPluginActivator\Config;
use Freezysko\ComposerWpPluginActivator\PluginRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginRunnerTest extends TestCase
{
    public function testEnforceDoesNothingOnSuccess(): void
    {
        PluginRunner::enforceFailureOnError(0, true);
        PluginRunner::enforceFailureOnError(0, false);

        $this->expectNotToPerformAssertions();
    }

    public function testEnforceDoesNotThrowWhenFailOnErrorIsFalse(): void
    {
        PluginRunner::enforceFailureOnError(1, false);

        $this->expectNotToPerformAssertions();
    }

    public function testEnforceThrowsWhenFailOnErrorIsTrueAndExitCodeNonZero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exit code 1');

        PluginRunner::enforceFailureOnError(1, true);
    }

    public function testRunCompletesSuccessfullyThroughFullChainWithFixtureWpCli(): void
    {
        $composer = $this->composer([
            'wp-cli' => __DIR__ . '/fixtures/wp',
            'skip-when-wp-not-installed' => false,
            'plugins' => 'all',
        ]);

        $runner = new PluginRunner($composer, $this->createMock(IOInterface::class));

        $runner->run();

        $this->expectNotToPerformAssertions();
    }

    public function testRunThrowsWhenWpCliActivationFailsAndFailOnErrorIsTrue(): void
    {
        $composer = $this->composer([
            'wp-cli' => __DIR__ . '/fixtures/wp-activate-fails',
            'skip-when-wp-not-installed' => false,
            'plugins' => ['hello'],
            'fail-on-error' => true,
        ]);

        $runner = new PluginRunner($composer, $this->createMock(IOInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exit code 1');

        $runner->run();
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function composer(array $pluginConfig): Composer
    {
        $package = $this->createMock(RootPackageInterface::class);
        $package->method('getExtra')->willReturn([Config::EXTRA_KEY => $pluginConfig]);

        $localRepo = $this->createMock(InstalledRepositoryInterface::class);
        $localRepo->method('getPackages')->willReturn([]);

        $repoManager = $this->createMock(RepositoryManager::class);
        $repoManager->method('getLocalRepository')->willReturn($localRepo);

        $composer = $this->createMock(Composer::class);
        $composer->method('getPackage')->willReturn($package);
        $composer->method('getRepositoryManager')->willReturn($repoManager);
        $composer->method('getInstallationManager')->willReturn($this->createMock(InstallationManager::class));

        return $composer;
    }
}
