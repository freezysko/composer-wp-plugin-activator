<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\Util\ProcessExecutor;

// Not final: ActivatorTest mocks this. Treat as internal/effectively-final.
class WpCli
{
    public function __construct(
        private readonly Config $config,
        private readonly ProcessExecutor $executor,
    ) {}

    public function binaryExists(): bool
    {
        return $this->run(['--version'], includePath: false)->isSuccessful();
    }

    public function isWordPressInstalled(): bool
    {
        return $this->run(['core', 'is-installed'])->isSuccessful();
    }

    public function activateAll(): WpCliResult
    {
        return $this->run(['plugin', 'activate', '--all']);
    }

    /**
     * @param list<string> $slugs
     */
    public function activate(array $slugs): WpCliResult
    {
        return $this->run(array_merge(['plugin', 'activate'], $slugs));
    }

    /**
     * @param list<string> $argv
     */
    private function run(array $argv, bool $includePath = true): WpCliResult
    {
        $command = $this->buildCommand($argv, $includePath);

        $output = '';
        $exitCode = $this->executor->execute($command, $output);
        $errorOutput = $this->executor->getErrorOutput();

        // ProcessExecutor::execute()'s by-ref $output is typed mixed in
        // Composer's stubs; narrow it back to string for PHPStan max.
        $combined = trim((\is_string($output) ? $output : '') . "\n" . $errorOutput);

        return new WpCliResult($exitCode, $combined);
    }

    /**
     * @param list<string> $argv
     */
    private function buildCommand(array $argv, bool $includePath): string
    {
        $parts = [ProcessExecutor::escape($this->config->getWpCli())];

        foreach ($argv as $arg) {
            $parts[] = ProcessExecutor::escape($arg);
        }

        $wpPath = $this->config->getWpPath();
        if ($includePath && $wpPath !== null) {
            $parts[] = ProcessExecutor::escape('--path=' . $wpPath);
        }

        if ($this->shouldAllowRoot()) {
            $parts[] = '--allow-root';
        }

        return implode(' ', $parts);
    }

    private function shouldAllowRoot(): bool
    {
        return match ($this->config->getAllowRootMode()) {
            Config::ALLOW_ROOT_ALWAYS => true,
            Config::ALLOW_ROOT_NEVER => false,
            default => \function_exists('posix_getuid') && posix_getuid() === 0,
        };
    }
}
