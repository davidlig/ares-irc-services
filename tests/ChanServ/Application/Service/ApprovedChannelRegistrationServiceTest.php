<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\ChannelDeregistrationOutcome;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\ChanServ\Application\Service\ApprovedChannelRegistrationService;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleOutcome;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleResult;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\Tests\ChanServ\Fake\RecordingRegisterChannelHandler;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approval path runs forced and privileged: the requester is the founder, the oper is
 * neither in the channel nor the founder, so the self-service guards must not apply. The
 * deregistration path (dropRegistered) hands the drop to the lifecycle use case and maps its
 * outcome onto the public ChannelDeregistrationOutcome.
 */
#[CoversClass(ApprovedChannelRegistrationService::class)]
final class ApprovedChannelRegistrationServiceTest extends TestCase
{
    private function service(
        RecordingRegisterChannelHandler $handler,
        ManageChannelLifecycleHandlerInterface $lifecycle,
    ): ApprovedChannelRegistrationService {
        return new ApprovedChannelRegistrationService($handler, $lifecycle);
    }

    private function register(
        RecordingRegisterChannelHandler $handler,
    ): ChannelRegistrationOutcome {
        $lifecycle = $this->createStub(ManageChannelLifecycleHandlerInterface::class);

        return $this->service($handler, $lifecycle)->registerApproved(
            '#SitioChat',
            'Canal de pruebas',
            7,
            'NetAdmin',
            new DateTimeImmutable('2026-10-15 12:00:00'),
        );
    }

    #[Test]
    public function registersForcingOnBehalfOfTheRequester(): void
    {
        $handler = new RecordingRegisterChannelHandler(RegisterChannelResult::registered());

        self::assertSame(ChannelRegistrationOutcome::Registered, $this->register($handler));

        self::assertCount(1, $handler->received);
        $command = $handler->received[0];
        self::assertSame('#SitioChat', $command->channelName);
        self::assertSame('Canal de pruebas', $command->description);
        self::assertSame(7, $command->accountId, 'The requester stays the founder.');
        self::assertSame('NetAdmin', $command->actorNickname);
        self::assertTrue($command->force, 'An approved registration must bypass the self-service guards.');
        self::assertTrue($command->actorIrcOperator);
        self::assertTrue($command->actorIdentified);
    }

    #[Test]
    public function reportsAFailedRegistration(): void
    {
        $handler = new RecordingRegisterChannelHandler(RegisterChannelResult::founderLimitExceeded(10));

        self::assertSame(ChannelRegistrationOutcome::Failed, $this->register($handler));
    }

    #[Test]
    public function reportsAPendingDeletion(): void
    {
        $handler = new RecordingRegisterChannelHandler(RegisterChannelResult::pendingDeletion());

        self::assertSame(ChannelRegistrationOutcome::PendingDeletion, $this->register($handler));
    }

    #[Test]
    public function reportsAnAlreadyRegisteredChannelWithoutPropagatingTheException(): void
    {
        $handler = new RecordingRegisterChannelHandler(
            RegisterChannelResult::registered(),
            ChannelAlreadyRegisteredException::forChannel('#SitioChat'),
        );

        self::assertSame(ChannelRegistrationOutcome::AlreadyRegistered, $this->register($handler));
    }

    private function dropRegistered(ChannelLifecycleOutcome $given): ChannelDeregistrationOutcome
    {
        $lifecycle = $this->createStub(ManageChannelLifecycleHandlerInterface::class);
        $lifecycle->method('handle')->willReturn(new ChannelLifecycleResult($given));

        $service = new ApprovedChannelRegistrationService(
            new RecordingRegisterChannelHandler(RegisterChannelResult::registered()),
            $lifecycle,
        );

        return $service->dropRegistered('#SitioChat', 'NetAdmin', new DateTimeImmutable('2026-10-15 12:00:00'));
    }

    /**
     * @return array<string, array{ChannelLifecycleOutcome, ChannelDeregistrationOutcome}>
     */
    public static function deregistrationProvider(): array
    {
        return [
            'soft dropped' => [ChannelLifecycleOutcome::Dropped, ChannelDeregistrationOutcome::Dropped],
            'force dropped' => [ChannelLifecycleOutcome::ForceDropped, ChannelDeregistrationOutcome::Dropped],
            'not registered' => [ChannelLifecycleOutcome::NotRegistered, ChannelDeregistrationOutcome::NotRegistered],
            'pending deletion' => [ChannelLifecycleOutcome::PendingDeletion, ChannelDeregistrationOutcome::Failed],
        ];
    }

    #[Test]
    #[DataProvider('deregistrationProvider')]
    public function mapsTheLifecycleOutcomeOntoTheDeregistrationOutcome(
        ChannelLifecycleOutcome $given,
        ChannelDeregistrationOutcome $expected,
    ): void {
        self::assertSame($expected, $this->dropRegistered($given));
    }

    #[Test]
    public function dropsTheChannelThroughTheLifecycleUseCase(): void
    {
        $lifecycle = $this->createMock(ManageChannelLifecycleHandlerInterface::class);
        $lifecycle->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ManageChannelLifecycle $command): ChannelLifecycleResult {
                self::assertSame('#SitioChat', $command->channelName);
                self::assertSame('NetAdmin', $command->actorNickname);

                return new ChannelLifecycleResult(ChannelLifecycleOutcome::Dropped);
            });

        $service = new ApprovedChannelRegistrationService(
            new RecordingRegisterChannelHandler(RegisterChannelResult::registered()),
            $lifecycle,
        );

        self::assertSame(
            ChannelDeregistrationOutcome::Dropped,
            $service->dropRegistered('#SitioChat', 'NetAdmin', new DateTimeImmutable('2026-10-15 12:00:00')),
        );
    }
}
