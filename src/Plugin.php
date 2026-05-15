<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Thin Composer adapter: stores Composer/IO on activation and forwards
 * post-install / post-update events to `PluginRunner`. All orchestration
 * and policy lives in `PluginRunner`.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No-op. Required by PluginInterface.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No-op. Required by PluginInterface.
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        (new PluginRunner($this->composer, $this->io))->run();
    }
}
