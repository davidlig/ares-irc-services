<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneChannelModeSupport;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneModule;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNickReservation;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneProtocolServiceActions;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneServiceIntroductionFormatter;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(UnrealStandaloneModule::class)]
final class UnrealStandaloneModuleTest extends TestCase
{
    private function createModule(): UnrealStandaloneModule
    {
        $handler = new UnrealStandaloneProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $serviceActions = new UnrealStandaloneProtocolServiceActions($connectionHolder, new UnrealStandaloneServiceIntroductionFormatter(), new NullLogger());
        $channelModeSupport = new UnrealStandaloneChannelModeSupport();
        $userModeSupport = new UnrealStandaloneUserModeSupport();
        $nickReservation = new UnrealStandaloneNickReservation($connectionHolder, new NullLogger());

        return new UnrealStandaloneModule(
            $handler,
            $serviceActions,
            $channelModeSupport,
            $userModeSupport,
            $nickReservation,
        );
    }

    #[Test]
    public function getProtocolNameReturnsUnreal(): void
    {
        $module = $this->createModule();

        self::assertSame(UnrealStandaloneModule::PROTOCOL_NAME, $module->getProtocolName());
        self::assertSame('unreal', $module->getProtocolName());
    }

    #[Test]
    public function getHandlerReturnsInjectedHandler(): void
    {
        $handler = new UnrealStandaloneProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $module = new UnrealStandaloneModule(
            $handler,
            new UnrealStandaloneProtocolServiceActions($connectionHolder, new UnrealStandaloneServiceIntroductionFormatter(), new NullLogger()),
            new UnrealStandaloneChannelModeSupport(),
            new UnrealStandaloneUserModeSupport(),
            new UnrealStandaloneNickReservation($connectionHolder, new NullLogger()),
        );

        self::assertSame($handler, $module->getHandler());
    }

    #[Test]
    public function getServiceActionsReturnsInjectedActions(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealStandaloneProtocolServiceActions::class, $module->getServiceActions());
    }

    #[Test]
    public function getChannelModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealStandaloneChannelModeSupport::class, $module->getChannelModeSupport());
    }

    #[Test]
    public function getNickReservationReturnsInjectedReservation(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealStandaloneNickReservation::class, $module->getNickReservation());
    }

    #[Test]
    public function getUserModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealStandaloneUserModeSupport::class, $module->getUserModeSupport());
    }
}
