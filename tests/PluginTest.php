<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\Script\ScriptEvents;
use Freezysko\ComposerWpPluginActivator\Plugin;
use PHPUnit\Framework\TestCase;

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
}
