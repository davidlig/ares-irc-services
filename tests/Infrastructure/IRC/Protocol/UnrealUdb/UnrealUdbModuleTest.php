<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Application\Port\UdbRawCommandResult;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
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
    use CreatesUdbRecordWriter;

    private function createModule(): UnrealUdbModule
    {
        $connectionHolder = new ActiveConnectionHolder();
        $recordWriter = new UnrealUdbRecordWriter(
            $connectionHolder,
            $this->createReadySessionState(),
            $this->createStub(UdbRecordRepositoryInterface::class),
            '001',
        );
        $handler = new UnrealUdbProtocolHandler('001', $this->createCoordinator());
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
            $this->createStub(UdbRawCommandHandlerInterface::class),
        );
    }

    private function createCoordinator(): UdbSessionCoordinator
    {
        return new UdbSessionCoordinator(
            '001',
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
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
        $connectionHolder = new ActiveConnectionHolder();
        $recordWriter = new UnrealUdbRecordWriter(
            $connectionHolder,
            $this->createReadySessionState(),
            $this->createStub(UdbRecordRepositoryInterface::class),
            '001',
        );
        $handler = new UnrealUdbProtocolHandler('001', $this->createCoordinator());
        $module = new UnrealUdbModule(
            $handler,
            new UnrealUdbProtocolServiceActions($connectionHolder, $recordWriter),
            new UnrealUdbServiceIntroductionFormatter(),
            new UnrealUdbChannelModeSupport(),
            new UnrealUdbUserModeSupport(),
            new UnrealUdbNickReservation($recordWriter),
            $this->createStub(UdbRawCommandHandlerInterface::class),
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

        $implementedInterfaces = class_implements($module);
        self::assertArrayHasKey(NickChangePreservesIdentificationInterface::class, $implementedInterfaces);
    }

    #[Test]
    public function delegatesRawCommandsToInjectedCapability(): void
    {
        $rawCommands = $this->createMock(UdbRawCommandHandlerInterface::class);
        $rawCommands->expects(self::once())->method('ins')->with('S::propagator', 'hub.example')
            ->willReturn(UdbRawCommandResult::success('inserted'));
        $rawCommands->expects(self::once())->method('del')->with('N::nick')
            ->willReturn(UdbRawCommandResult::success('deleted'));

        $connectionHolder = new ActiveConnectionHolder();
        $recordWriter = new UnrealUdbRecordWriter(
            $connectionHolder,
            $this->createReadySessionState(),
            $this->createStub(UdbRecordRepositoryInterface::class),
            '001',
        );
        $module = new UnrealUdbModule(
            new UnrealUdbProtocolHandler('001', $this->createCoordinator()),
            new UnrealUdbProtocolServiceActions($connectionHolder, $recordWriter),
            new UnrealUdbServiceIntroductionFormatter(),
            new UnrealUdbChannelModeSupport(),
            new UnrealUdbUserModeSupport(),
            new UnrealUdbNickReservation($recordWriter),
            $rawCommands,
        );

        self::assertSame('inserted', $module->ins('S::propagator', 'hub.example')->auditLine);
        self::assertSame('deleted', $module->del('N::nick')->auditLine);
    }
}
