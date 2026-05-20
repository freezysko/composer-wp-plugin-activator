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

    public function testBinaryExistsPassesExactlyTheVersionArgv(): void
    {
        // The strict fixture exits non-zero unless argv is exactly `--version`,
        // so dropping that item (ArrayItemRemoval) flips the result to false.
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp-strict-args']);

        self::assertTrue($wpCli->binaryExists());
    }

    public function testBinaryExistsDoesNotAppendWpPathEvenWhenConfigured(): void
    {
        // binaryExists() calls run(..., includePath: false). The strict fixture
        // fails if argv is anything but `--version`, so an appended `--path=`
        // (FalseValue mutation flipping includePath to true) makes it fail.
        $wpCli = $this->wpCli([
            'wp-cli' => self::FIXTURE_DIR . '/wp-strict-args',
            'wp-path' => '/srv/site/web/wp',
        ]);

        self::assertTrue($wpCli->binaryExists());
    }

    public function testIsWordPressInstalledPassesExactlyTheCoreIsInstalledArgv(): void
    {
        // The strict fixture only exits zero for the exact `core is-installed`
        // pair, so dropping the `core` item (ArrayItemRemoval) breaks it.
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp-strict-args']);

        self::assertTrue($wpCli->isWordPressInstalled());
    }

    public function testRunJoinsStdoutAndStderrWithExactlyOneNewline(): void
    {
        // The fixture writes one token to stdout and one to stderr, each with
        // no trailing newline. WpCli::run() must combine them as
        // `trim(stdout . "\n" . stderr)`, so the exact result pins the join:
        // stdout first, stderr second, separated by a single "\n". Any Concat
        // reorder or ConcatOperandRemoval on that separator changes the string.
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp-stdout-stderr']);

        $result = $wpCli->activateAll();

        self::assertSame("STDOUT-LINE\nSTDERR-LINE", $result->output);
    }

    public function testAllowRootIsOmittedUnderDefaultAutoModeOnNonRootEnvironment(): void
    {
        // The test runner is non-root, so `auto` mode must resolve to NOT
        // appending --allow-root. This pins the `posix_getuid() === 0` arm:
        // negating the comparison or the `&&` would append the flag here.
        $wpCli = $this->wpCli(['wp-cli' => self::FIXTURE_DIR . '/wp']);

        $result = $wpCli->activateAll();

        self::assertStringNotContainsString('--allow-root', $result->output);
    }
}
