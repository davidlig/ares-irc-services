<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbChannelModeSupport;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbModule;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbNickReservation;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolServiceActions;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbServiceIntroductionFormatter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbModule::class)]
final class UnrealUdbModuleTest extends TestCase
{
    private function createModule(): UnrealUdbModule
    {
        $handler = new UnrealUdbProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $recordWriter = new UnrealUdbRecordWriter($connectionHolder);
        $serviceActions = new UnrealUdbProtocolServiceActions($connectionHolder, $recordWriter);
        $formatter = new UnrealUdbServiceIntroductionFormatter();
        $channelModeSupport = new UnrealUdbChannelModeSupport();
        $userModeSupport = new UnrealUdbUserModeSupport();
        $nickReservation = new UnrealUdbNickReservation($recordWriter);

        return new UnrealUdbModule(
            $handler,
            $serviceActions,
            $formatter,
            $channelModeSupport,
            $userModeSupport,
            $nickReservation,
        );
    }

    #[Test]
    public function getProtocolNameReturnsUnreal(): void
    {
        $module = $this->createModule();

        self::assertSame(UnrealUdbModule::PROTOCOL_NAME, $module->getProtocolName());
        self::assertSame('unrealudb', $module->getProtocolName());
    }

    #[Test]
    public function getHandlerReturnsInjectedHandler(): void
    {
        $handler = new UnrealUdbProtocolHandler('001');
        $connectionHolder = new ActiveConnectionHolder();
        $recordWriter = new UnrealUdbRecordWriter($connectionHolder);
        $module = new UnrealUdbModule(
            $handler,
            new UnrealUdbProtocolServiceActions($connectionHolder, $recordWriter),
            new UnrealUdbServiceIntroductionFormatter(),
            new UnrealUdbChannelModeSupport(),
            new UnrealUdbUserModeSupport(),
            new UnrealUdbNickReservation($recordWriter),
        );

        self::assertSame($handler, $module->getHandler());
    }

    #[Test]
    public function getServiceActionsReturnsInjectedActions(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealUdbProtocolServiceActions::class, $module->getServiceActions());
    }

    #[Test]
    public function getIntroductionFormatterReturnsInjectedFormatter(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealUdbServiceIntroductionFormatter::class, $module->getIntroductionFormatter());
    }

    #[Test]
    public function getChannelModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealUdbChannelModeSupport::class, $module->getChannelModeSupport());
    }

    #[Test]
    public function getNickReservationReturnsInjectedReservation(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealUdbNickReservation::class, $module->getNickReservation());
    }

    #[Test]
    public function getUserModeSupportReturnsInjectedSupport(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(UnrealUdbUserModeSupport::class, $module->getUserModeSupport());
    }

    #[Test]
    public function implementsNickChangePreservesIdentificationInterface(): void
    {
        $module = $this->createModule();

        self::assertInstanceOf(NickChangePreservesIdentificationInterface::class, $module);
    }
}
