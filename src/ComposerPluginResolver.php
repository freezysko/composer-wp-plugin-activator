<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\Composer;
use Composer\IO\IOInterface;

final class ComposerPluginResolver
{
    private const WORDPRESS_PLUGIN_TYPE = 'wordpress-plugin';

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Derive WP plugin slugs from Composer-managed packages of type
     * "wordpress-plugin". The slug is the basename of the actual install
     * path, which equals the directory name WP-CLI expects.
     *
     * Slugs are validated with the same rule as user-supplied entries
     * (`Config::isValidSlug`). Composer install paths are normally safe,
     * but `composer/installers` is configurable and a malformed
     * `installer-paths` entry could yield a basename WP-CLI would
     * misinterpret as an option. Invalid slugs are skipped with a warning.
     *
     * @return list<string>
     */
    public function resolve(): array
    {
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $this->composer->getInstallationManager();

        $slugs = [];
        foreach ($localRepository->getPackages() as $package) {
            if ($package->getType() !== self::WORDPRESS_PLUGIN_TYPE) {
                continue;
            }

            $path = $installationManager->getInstallPath($package);
            if ($path === null || $path === '') {
                continue;
            }

            $slug = basename($path);
            if (!Config::isValidSlug($slug)) {
                $this->io->writeError(sprintf(
                    '<warning>composer-wp-plugin-activator: Composer package "%s" '
                    . 'resolved to install-path basename "%s", which is not a valid '
                    . 'plugin slug; skipping</warning>',
                    $package->getName(),
                    $slug
                ));

                continue;
            }

            $slugs[] = $slug;
        }

        return $slugs;
    }
}
