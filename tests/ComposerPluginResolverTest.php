<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Freezysko\ComposerWpPluginActivator\ComposerPluginResolver;
use PHPUnit\Framework\TestCase;
use SplObjectStorage;

final class ComposerPluginResolverTest extends TestCase
{
    private function package(string $type, string $name = 'vendor/dummy'): PackageInterface
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getType')->willReturn($type);
        $package->method('getName')->willReturn($name);

        return $package;
    }

    private function silentIo(): IOInterface
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('writeError');

        return $io;
    }

    /**
     * @param list<PackageInterface> $packages
     * @param array<int, string|null> $installPaths indexed parallel to $packages
     */
    private function composer(array $packages, array $installPaths): Composer
    {
        $localRepo = $this->createMock(InstalledRepositoryInterface::class);
        $localRepo->method('getPackages')->willReturn($packages);

        $repoManager = $this->createMock(RepositoryManager::class);
        $repoManager->method('getLocalRepository')->willReturn($localRepo);

        // Identity-keyed lookup: two createMock() packages are not reliably
        // distinguishable by willReturnMap's ==-style matching, so match on
        // object identity instead.
        /** @var SplObjectStorage<PackageInterface, string|null> $pathByPackage */
        $pathByPackage = new SplObjectStorage();
        foreach ($packages as $index => $package) {
            $pathByPackage[$package] = $installPaths[$index] ?? null;
        }

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->method('getInstallPath')->willReturnCallback(
            static fn(PackageInterface $package): ?string => $pathByPackage[$package] ?? null
        );

        $composer = $this->createMock(Composer::class);
        $composer->method('getRepositoryManager')->willReturn($repoManager);
        $composer->method('getInstallationManager')->willReturn($installationManager);

        return $composer;
    }

    public function testResolvesOnlyWordpressPluginPackages(): void
    {
        $wpPlugin = $this->package('wordpress-plugin');
        $library = $this->package('library');

        $composer = $this->composer(
            [$wpPlugin, $library],
            ['/srv/site/web/app/plugins/woocommerce', '/srv/site/vendor/foo/bar']
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame(['woocommerce'], $resolver->resolve());
    }

    public function testReturnsEmptyArrayWhenNoWordpressPlugins(): void
    {
        $composer = $this->composer(
            [$this->package('library'), $this->package('metapackage')],
            ['/srv/site/vendor/a', '/srv/site/vendor/b']
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame([], $resolver->resolve());
    }

    public function testSkipsPackagesWithoutResolvableInstallPath(): void
    {
        $composer = $this->composer(
            [$this->package('wordpress-plugin')],
            [null]
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame([], $resolver->resolve());
    }

    public function testDerivesSlugFromInstallDirectoryBasename(): void
    {
        $composer = $this->composer(
            [$this->package('wordpress-plugin'), $this->package('wordpress-plugin')],
            ['/var/www/web/app/plugins/polylang', '/var/www/web/app/plugins/woo-variation-swatches']
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame(['polylang', 'woo-variation-swatches'], $resolver->resolve());
    }

    public function testSkipsPackagesWhoseInstallPathBasenameIsNotAValidSlug(): void
    {
        $valid = $this->package('wordpress-plugin', 'wpackagist-plugin/woocommerce');
        $bogus = $this->package('wordpress-plugin', 'evil/dash-prefix');

        $composer = $this->composer(
            [$valid, $bogus],
            ['/var/www/web/app/plugins/woocommerce', '/var/www/web/app/plugins/-bad']
        );

        $errorWrites = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (string $message) use (&$errorWrites): void {
            $errorWrites[] = $message;
        });

        $resolver = new ComposerPluginResolver($composer, $io);

        self::assertSame(['woocommerce'], $resolver->resolve());

        self::assertContains(
            '<warning>composer-wp-plugin-activator: Composer package "evil/dash-prefix" '
            . 'resolved to install-path basename "-bad", which is not a valid '
            . 'plugin slug; skipping</warning>',
            $errorWrites
        );
    }

    public function testNonPluginPackageBeforePluginPackageIsSkippedNotLoopBroken(): void
    {
        $composer = $this->composer(
            [$this->package('library'), $this->package('wordpress-plugin')],
            ['/srv/vendor/some/lib', '/var/www/web/app/plugins/woocommerce']
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame(['woocommerce'], $resolver->resolve());
    }

    public function testPackageWithoutInstallPathBeforePluginPackageIsSkippedNotLoopBroken(): void
    {
        $composer = $this->composer(
            [$this->package('wordpress-plugin'), $this->package('wordpress-plugin')],
            [null, '/var/www/web/app/plugins/polylang']
        );

        $resolver = new ComposerPluginResolver($composer, $this->silentIo());

        self::assertSame(['polylang'], $resolver->resolve());
    }

    public function testInvalidSlugPackageBeforeValidOneIsSkippedNotLoopBroken(): void
    {
        $composer = $this->composer(
            [$this->package('wordpress-plugin', 'evil/-bad'), $this->package('wordpress-plugin')],
            ['/var/www/web/app/plugins/-bad', '/var/www/web/app/plugins/woocommerce']
        );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())->method('writeError');

        $resolver = new ComposerPluginResolver($composer, $io);

        self::assertSame(['woocommerce'], $resolver->resolve());
    }
}
