<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Freezysko\ComposerWpPluginActivator\WpCliResult;
use PHPUnit\Framework\TestCase;

final class WpCliResultTest extends TestCase
{
    public function testExposesExitCodeAndOutput(): void
    {
        $result = new WpCliResult(2, 'some output');

        self::assertSame(2, $result->exitCode);
        self::assertSame('some output', $result->output);
    }

    public function testIsSuccessfulWhenExitCodeIsZero(): void
    {
        self::assertTrue((new WpCliResult(0, ''))->isSuccessful());
    }

    public function testIsNotSuccessfulWhenExitCodeIsNonZero(): void
    {
        self::assertFalse((new WpCliResult(1, ''))->isSuccessful());
    }
}
