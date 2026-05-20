<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Freezysko\ComposerWpPluginActivator\Config;
use Freezysko\ComposerWpPluginActivator\Plugin;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginTest extends TestCase
{
    public function testSubscribesToPostInstallAndPostUpdateEvents(): void
    {
        $events = Plugin::getSubscribedEvents();

        self::assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, $events);
        self::assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, $events);
        self::assertSame('onPostInstallOrUpdate', $events[ScriptEvents::POST_INSTALL_CMD]);
        self::assertSame('onPostInstallOrUpdate', $events[ScriptEvents::POST_UPDATE_CMD]);
    }

    public function testOnPostInstallOrUpdateIsCallableFromOutsideTheClass(): void
    {
        // The event handler must stay `public` — Composer's event dispatcher
        // invokes it by name from outside the class. A `protected` mutation
        // would make this very call a fatal error.
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($this->composer([]), $io);

        $plugin->onPostInstallOrUpdate($this->createMock(Event::class));

        $this->expectNotToPerformAssertions();
    }

    public function testOnPostInstallOrUpdateActuallyRunsTheActivationChain(): void
    {
        // Wire a real Composer whose extra drives a failing wp-cli with
        // fail-on-error. If the handler genuinely delegates to PluginRunner,
        // the activation failure surfaces as a thrown RuntimeException.
        // The MethodCallRemoval mutant (empty body) would swallow it.
        $composer = $this->composer([
            'wp-cli' => __DIR__ . '/fixtures/wp-activate-fails',
            'skip-when-wp-not-installed' => false,
            'plugins' => ['hello'],
            'fail-on-error' => true,
        ]);

        $plugin = new Plugin();
        $plugin->activate($composer, $this->createMock(IOInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exit code 1');

        $plugin->onPostInstallOrUpdate($this->createMock(Event::class));
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
