<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdModule;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNickReservation;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdServiceIntroductionFormatter;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(InspIRCdModule::class)]
final class InspIRCdModuleTest extends TestCase
{
    private function createModule(): InspIRCdModule
    {
        $handler = new InspIRCdProtocolHandler('A0A');
        $connectionHolder = new ActiveConnectionHolder();
        $serviceActions = new InspIRCdProtocolServiceActions($connectionHolder, new NullLogger());
        $formatter = new InspIRCdServiceIntroductionFormatter();
        $vhostBuilder = new InspIRCdVhostCommandBuilder();
        $channelModeSupport = new InspIRCdChannelModeSupport();
        $nickReservation = new InspIRCdNickReservation(new NullLogger());

        return new InspIRCdModule(
            $handler,
            $serviceActions,
            $formatter,
            $vhostBuilder,
            $channelModeSupport,
            $nickReservation,
        );
    }

    #[Test]
    public function getProtocolNameReturnsInspircd(): void
    {
        $module = $this->createModule();

        self::assertSame(InspIRCdModule::PROTOCOL_NAME, $module->getProtocolName());
        self::assertSame('inspircd', $module->getProtocolName());
    }

    #[Test]
    public function getHandlerReturnsInjectedHandler(): void
    {
        $handler = new InspIRCdProtocolHandler('A0A');
        $connectionHolder = new ActiveConnectionHolder();
        $module = new InspIRCdModule(
            $handler,
            new InspIRCdProtocolServiceActions($connectionHolder, new NullLogger()),
            new InspIRCdServiceIntroductionFormatter(),
            new InspIRCdVhostCommandBuilder(),
            new InspIRCdChannelModeSupport(),
            new InspIRCdNickReservation(new NullLogger()),
        );

        self::assertSame($handler, $module->getHandler());
    }

    #[Test]
    public function getServiceActionsGetIntroductionFormatterGetVhostCommandBuilderGetChannelModeSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(InspIRCdProtocolServiceActions::class, $module->getServiceActions());
        self::assertInstanceOf(InspIRCdServiceIntroductionFormatter::class, $module->getIntroductionFormatter());
        self::assertInstanceOf(InspIRCdVhostCommandBuilder::class, $module->getVhostCommandBuilder());
        self::assertInstanceOf(InspIRCdChannelModeSupport::class, $module->getChannelModeSupport());
    }

    #[Test]
    public function getNickReservationReturnsInjectedReservation(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(InspIRCdNickReservation::class, $module->getNickReservation());
    }
}
