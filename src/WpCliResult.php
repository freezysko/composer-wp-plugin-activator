<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

final class WpCliResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
