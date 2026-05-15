<?php

declare(strict_types=1);

namespace Freezysko\ComposerWpPluginActivator\Tests;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Freezysko\ComposerWpPluginActivator\Config;
use Freezysko\ComposerWpPluginActivator\WpCli;
use PHPUnit\Framework\TestCase;

final class WpCliTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    private function io(): IOInterface
    {
        return $this->createMock(IOInterface::class);
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function wpCli(array $pluginConfig): WpCli
    {
        $config = Config::fromExtra(
            [Config::EXTRA_KEY => $pluginConfig],
            $this->io()
        );

        return new WpCli($config, new ProcessExecutor());
    }

    public function testBinaryExistsReturnsTrueForRunnableBinary(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        self::assertTrue($wpCli->binaryExists());
    }

    public function testBinaryExistsReturnsFalseForMissingBinary(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/does-not-exist']);

        self::assertFalse($wpCli->binaryExists());
    }

    public function testIsWordPressInstalledReturnsTrueOnExitZero(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        self::assertTrue($wpCli->isWordPressInstalled());
    }

    public function testIsWordPressInstalledReturnsFalseOnNonZeroExit(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp-fail']);

        self::assertFalse($wpCli->isWordPressInstalled());
    }

    public function testActivateAllBuildsActivateAllCommand(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        $result = $wpCli->activateAll();

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('[plugin] [activate] [--all]', $result->output);
    }

    public function testActivatePassesSlugsInOrder(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        $result = $wpCli->activate(['woocommerce', 'polylang']);

        self::assertStringContainsString('[plugin] [activate] [woocommerce] [polylang]', $result->output);
    }

    public function testWpPathIsAppendedWhenSet(): void
    {
        $wpCli = $this->wpCli([
            'wp-cli' => self::FIXTURE_DIR . '/wp',
            'wp-path' => '/srv/site/web/wp',
        ]);

        $result = $wpCli->activateAll();

        self::assertStringContainsString('--path=/srv/site/web/wp', $result->output);
    }

    public function testWpPathIsOmittedWhenNull(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        $result = $wpCli->activateAll();

        self::assertStringNotContainsString('--path=', $result->output);
    }

    public function testAllowRootIsAppendedWhenModeIsAlways(): void
    {
        $wpCli = $this->wpCli([
            'wp-cli' => self::FIXTURE_DIR . '/wp',
            'allow-root' => true,
        ]);

        $result = $wpCli->activateAll();

        self::assertStringContainsString('--allow-root', $result->output);
    }

    public function testAllowRootIsOmittedWhenModeIsNever(): void
    {
        $wpCli = $this->wpCli([
            'wp-cli' => self::FIXTURE_DIR . '/wp',
            'allow-root' => false,
        ]);

        $result = $wpCli->activateAll();

        self::assertStringNotContainsString('--allow-root', $result->output);
    }

    public function testSlugsWithSpacesAreEscapedAsSingleArguments(): void
    {
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        $result = $wpCli->activate(['my plugin']);

        // The fixture brackets each received argument. A properly escaped slug
        // containing a space must arrive as ONE argument, not two.
        self::assertStringContainsString('[plugin] [activate] [my plugin]', $result->output);
        self::assertStringNotContainsString('[my] [plugin]', $result->output);
    }
}
