<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdChannelModeSupport;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdModule;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdProtocolHandler;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdProtocolServiceActions;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdServiceIntroductionFormatter;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(UnrealIRCdModule::class)]
final class UnrealIRCdModuleTest extends TestCase
{
    private function createModule(): UnrealIRCdModule
    {
        $handler = new UnrealIRCdProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $serviceActions = new UnrealIRCdProtocolServiceActions($connectionHolder, new NullLogger());
        $formatter = new UnrealIRCdServiceIntroductionFormatter();
        $vhostBuilder = new UnrealIRCdVhostCommandBuilder();
        $channelModeSupport = new UnrealIRCdChannelModeSupport();

        return new UnrealIRCdModule(
            $handler,
            $serviceActions,
            $formatter,
            $vhostBuilder,
            $channelModeSupport,
        );
    }

    #[Test]
    public function getProtocolNameReturnsUnreal(): void
    {
        $module = $this->createModule();

        self::assertSame(UnrealIRCdModule::PROTOCOL_NAME, $module->getProtocolName());
        self::assertSame('unreal', $module->getProtocolName());
    }

    #[Test]
    public function getHandlerReturnsInjectedHandler(): void
    {
        $handler = new UnrealIRCdProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $module = new UnrealIRCdModule(
            $handler,
            new UnrealIRCdProtocolServiceActions($connectionHolder, new NullLogger()),
            new UnrealIRCdServiceIntroductionFormatter(),
            new UnrealIRCdVhostCommandBuilder(),
            new UnrealIRCdChannelModeSupport(),
        );

        self::assertSame($handler, $module->getHandler());
    }

    #[Test]
    public function getServiceActionsReturnsInjectedActions(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealIRCdProtocolServiceActions::class, $module->getServiceActions());
    }

    #[Test]
    public function getIntroductionFormatterReturnsInjectedFormatter(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealIRCdServiceIntroductionFormatter::class, $module->getIntroductionFormatter());
    }

    #[Test]
    public function getVhostCommandBuilderReturnsInjectedBuilder(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealIRCdVhostCommandBuilder::class, $module->getVhostCommandBuilder());
    }

    #[Test]
    public function getChannelModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealIRCdChannelModeSupport::class, $module->getChannelModeSupport());
    }
}
