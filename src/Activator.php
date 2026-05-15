<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\IO\IOInterface;

final class Activator
{
    /**
     * Hard cap on activation passes. Each retry lets WordPress's own
     * `Requires Plugins:` dependency checks resolve one more layer of
     * ordering. Real-world dependency chains are shallow; 5 is generous.
     */
    public const MAX_ACTIVATION_ATTEMPTS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly WpCli $wpCli,
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Orchestrates activation. Returns an exit code; never throws. The
     * caller (Plugin) decides whether a non-zero code aborts Composer.
     *
     * @param callable(): list<string> $resolveComposerPlugins
     */
    public function run(callable $resolveComposerPlugins): int
    {
        if (!$this->wpCli->binaryExists()) {
            $this->io->writeError(sprintf(
                '<warning>composer-wp-plugin-activator: wp-cli not found (tried "%s"), '
                . 'skipping plugin activation</warning>',
                $this->config->getWpCli()
            ));

            return 0;
        }

        if ($this->config->shouldSkipWhenNotInstalled() && !$this->wpCli->isWordPressInstalled()) {
            $this->io->write(
                '<info>composer-wp-plugin-activator: WordPress not installed yet, '
                . 'skipping plugin activation</info>'
            );

            return 0;
        }

        $priority = $this->config->getPriority();
        $priorityResult = $priority !== [] ? $this->wpCli->activate($priority) : null;

        $plugins = $this->config->getPlugins();

        if ($plugins === 'all') {
            return $this->runMain(
                fn (): WpCliResult => $this->wpCli->activateAll(),
                $priorityResult
            );
        }

        if ($plugins === 'composer') {
            $slugs = $resolveComposerPlugins();
            if ($slugs === []) {
                return $this->reportNoMain(
                    $priorityResult,
                    'no Composer-managed plugins found'
                );
            }

            return $this->runMain(
                fn (): WpCliResult => $this->wpCli->activate($slugs),
                $priorityResult
            );
        }

        if ($plugins === []) {
            return $this->reportNoMain(
                $priorityResult,
                '"plugins" resolved to an empty list'
            );
        }

        return $this->runMain(
            fn (): WpCliResult => $this->wpCli->activate($plugins),
            $priorityResult
        );
    }

    /**
     * @param callable(): WpCliResult $mainActivate
     */
    private function runMain(callable $mainActivate, ?WpCliResult $priorityResult): int
    {
        $totalActivated = 0;
        /** @var list<string> $outputs */
        $outputs = [];
        $priorityFailureCode = 0;

        if ($priorityResult !== null) {
            $outputs[] = $priorityResult->output;
            $totalActivated += $this->countActivated($priorityResult->output);

            if (!$priorityResult->isSuccessful()) {
                // Always surface priority failures regardless of verbose —
                // the main pass keeps running for convergence, but the
                // operator needs to see what failed in the priority pass.
                $priorityFailureCode = $priorityResult->exitCode;
                $this->io->writeError(
                    '<error>composer-wp-plugin-activator: priority pass failed:</error>'
                );
                if ($priorityResult->output !== '') {
                    $this->io->writeError($priorityResult->output);
                }
            }
        }

        $mainExitCode = $this->activateWithRetry($mainActivate, $totalActivated, $outputs);

        // Preserve the priority failure in the final exit code so
        // `fail-on-error: true` can still abort Composer even when the
        // main pass converged successfully on retries.
        if ($mainExitCode === 0 && $priorityFailureCode !== 0) {
            return $priorityFailureCode;
        }

        return $mainExitCode;
    }

    /**
     * Reports the outcome when the main pass has nothing to do — either
     * because there were no Composer-managed plugins or because the
     * explicit `plugins` list was empty. Falls back to reporting on the
     * priority pass alone if it ran, otherwise emits a generic
     * "nothing to activate" info line.
     */
    private function reportNoMain(?WpCliResult $priorityResult, string $reason): int
    {
        if ($priorityResult === null) {
            $this->io->write(sprintf(
                '<info>composer-wp-plugin-activator: %s, nothing to activate</info>',
                $reason
            ));

            return 0;
        }

        if ($priorityResult->isSuccessful()) {
            return $this->reportSuccess(
                $this->countActivated($priorityResult->output),
                [$priorityResult->output]
            );
        }

        return $this->reportFailure($priorityResult);
    }

    /**
     * Runs the activation command in a bounded, idempotent retry loop.
     *
     * WordPress 6.5+ enforces `Requires Plugins:` dependencies but WP-CLI
     * does not reorder, so a dependent activated before its dependency
     * fails on that pass. Re-running picks up plugins whose dependencies
     * just became active. A pass that activates nothing new means the
     * remaining failures are genuine (missing dependency, real error),
     * not an ordering problem — stop there.
     *
     * `$totalActivated` and `$outputs` are seeded by the optional
     * priority pass (`runMain`) so the success report's totals and
     * verbose output cover both passes.
     *
     * @param callable(): WpCliResult $activate
     * @param list<string>            $outputs
     */
    private function activateWithRetry(callable $activate, int $totalActivated = 0, array $outputs = []): int
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $activate();
            $outputs[] = $result->output;
            $activatedThisPass = $this->countActivated($result->output);
            $totalActivated += $activatedThisPass;

            if ($result->isSuccessful()) {
                return $this->reportSuccess($totalActivated, $outputs);
            }

            if ($activatedThisPass === 0 || $attempt >= self::MAX_ACTIVATION_ATTEMPTS) {
                return $this->reportFailure($result);
            }
        }
    }

    /**
     * @param list<string> $outputs WP-CLI output captured from each pass
     */
    private function reportSuccess(int $totalActivated, array $outputs): int
    {
        if ($totalActivated === 0) {
            $this->io->write('<info>composer-wp-plugin-activator: all plugins already active.</info>');
        } else {
            $this->io->write(sprintf(
                '<info>composer-wp-plugin-activator: activated %d plugin(s).</info>',
                $totalActivated
            ));
        }

        if ($this->config->isVerbose()) {
            foreach ($outputs as $output) {
                if ($output !== '') {
                    $this->io->write($output);
                }
            }
        }

        return 0;
    }

    private function reportFailure(WpCliResult $result): int
    {
        $this->io->writeError('<error>composer-wp-plugin-activator: wp-cli reported an error:</error>');
        if ($result->output !== '') {
            $this->io->writeError($result->output);
        }

        return $result->exitCode;
    }

    /**
     * Best-effort count of activated plugins by parsing WP-CLI output.
     * Assumes English-locale WP-CLI output (documented limitation).
     *
     * The regex anchors on `Plugin '<slug>' activated.` so it does NOT
     * match failure lines like `Plugin '<slug>' could not be activated.`.
     * This distinction drives the retry loop's progress detection, so it
     * must be exact — not merely cosmetic.
     */
    private function countActivated(string $output): int
    {
        return (int) preg_match_all("/Plugin '[^']+' activated\\./", $output);
    }
}
