<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use RuntimeException;

/**
 * Orchestrates a single activation pass and applies the fail-on-error
 * policy. Sits between `Plugin` (Composer event-subscriber adapter) and
 * `Activator` (returns an exit code, never throws) so the adapter can
 * stay a thin wiring layer.
 *
 * `enforceFailureOnError()` is public for unit testing — it lives on an
 * internal class rather than on the Composer-facing `Plugin` entrypoint.
 */
final class PluginRunner
{
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {}

    public function run(): void
    {
        $config = Config::fromExtra(
            $this->composer->getPackage()->getExtra(),
            $this->io
        );

        $wpCli = new WpCli($config, new ProcessExecutor($this->io));
        $activator = new Activator($config, $wpCli, $this->io);
        $resolver = new ComposerPluginResolver($this->composer, $this->io);

        $exitCode = $activator->run(static fn(): array => $resolver->resolve());

        self::enforceFailureOnError($exitCode, $config->shouldFailOnError());
    }

    /**
     * Converts a non-zero Activator exit code into a thrown exception when
     * fail-on-error is enabled. Composer event subscribers abort the build
     * by throwing, not by return value — hence this lives at the runner
     * boundary, not in Activator.
     */
    public static function enforceFailureOnError(int $exitCode, bool $failOnError): void
    {
        if ($exitCode !== 0 && $failOnError) {
            throw new RuntimeException(\sprintf(
                'composer-wp-plugin-activator: wp-cli plugin activation failed with exit code %d',
                $exitCode
            ));
        }
    }
}
