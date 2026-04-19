<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupportFactory;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdModule;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNickReservation;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdServiceIntroductionFormatter;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdUserModeSupport;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(InspIRCdModule::class)]
final class InspIRCdModuleTest extends TestCase
{
    private InspIRCdChannelModeSupportFactory $modeSupportFactory;

    protected function setUp(): void
    {
        $this->modeSupportFactory = new InspIRCdChannelModeSupportFactory();
    }

    private function createModule(): InspIRCdModule
    {
        $handler = new InspIRCdProtocolHandler('A0A');
        $connectionHolder = new ActiveConnectionHolder();
        $serviceActions = new InspIRCdProtocolServiceActions($connectionHolder, new NullLogger());
        $formatter = new InspIRCdServiceIntroductionFormatter();
        $vhostBuilder = new InspIRCdVhostCommandBuilder();
        $channelModeSupport = $this->modeSupportFactory->createDefault();
        $userModeSupport = new InspIRCdUserModeSupport();
        $nickReservation = new InspIRCdNickReservation($connectionHolder, new NullLogger());

        return new InspIRCdModule(
            $handler,
            $serviceActions,
            $formatter,
            $vhostBuilder,
            $channelModeSupport,
            $userModeSupport,
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
            $this->modeSupportFactory->createDefault(),
            new InspIRCdUserModeSupport(),
            new InspIRCdNickReservation($connectionHolder, new NullLogger()),
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

    #[Test]
    public function getUserModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(InspIRCdUserModeSupport::class, $module->getUserModeSupport());
    }

    #[Test]
    public function updateChannelModeSupportReplacesTheInstance(): void
    {
        $module = $this->createModule();

        self::assertTrue($module->getChannelModeSupport()->hasPermanentChannelMode());
        self::assertTrue($module->getChannelModeSupport()->hasOwner());

        $newSupport = new InspIRCdChannelModeSupport(
            prefixModes: ['v', 'o'],
            listModeLetters: ['b'],
            channelSettingUnsetWithoutParam: ['i'],
            channelSettingUnsetWithParam: [],
            channelSettingWithParamOnSet: [],
            hasHalfOp: false,
            hasAdmin: false,
            hasOwner: false,
            hasPermanentMode: false,
            permanentModeLetter: null,
            hasRegisteredMode: true,
            registeredModeLetter: 'r',
        );

        $module->updateChannelModeSupport($newSupport);

        self::assertSame($newSupport, $module->getChannelModeSupport());
        self::assertFalse($module->getChannelModeSupport()->hasPermanentChannelMode());
        self::assertFalse($module->getChannelModeSupport()->hasOwner());
        self::assertSame(['v', 'o'], $module->getChannelModeSupport()->getSupportedPrefixModes());
    }
}
