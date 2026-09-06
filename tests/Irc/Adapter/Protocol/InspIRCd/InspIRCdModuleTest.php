<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelModeSupportFactory;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdModule;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNickReservation;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdServiceIntroductionFormatter;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdUserModeSupport;
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
        $serviceActions = new InspIRCdProtocolServiceActions($connectionHolder, new InspIRCdServiceIntroductionFormatter(), new NullLogger());
        $formatter = new InspIRCdServiceIntroductionFormatter();
        $channelModeSupport = $this->modeSupportFactory->createDefault();
        $userModeSupport = new InspIRCdUserModeSupport();
        $nickReservation = new InspIRCdNickReservation($connectionHolder, new NullLogger());

        return new InspIRCdModule(
            $handler,
            $serviceActions,
            $formatter,
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
            new InspIRCdProtocolServiceActions($connectionHolder, new InspIRCdServiceIntroductionFormatter(), new NullLogger()),
            new InspIRCdServiceIntroductionFormatter(),
            $this->modeSupportFactory->createDefault(),
            new InspIRCdUserModeSupport(),
            new InspIRCdNickReservation($connectionHolder, new NullLogger()),
        );

        self::assertSame($handler, $module->getHandler());
    }

    #[Test]
    public function getServiceActionsGetIntroductionFormatterGetChannelModeSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(InspIRCdProtocolServiceActions::class, $module->getServiceActions());
        self::assertInstanceOf(InspIRCdServiceIntroductionFormatter::class, $module->getIntroductionFormatter());
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
