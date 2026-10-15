<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\ChanServ\Application\Service\ApprovedChannelRegistrationService;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\Tests\ChanServ\Fake\RecordingRegisterChannelHandler;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approval path runs forced and privileged: the requester is the founder, the oper is
 * neither in the channel nor the founder, so the self-service guards must not apply.
 */
#[CoversClass(ApprovedChannelRegistrationService::class)]
final class ApprovedChannelRegistrationServiceTest extends TestCase
{
    private function register(
        RecordingRegisterChannelHandler $handler,
    ): ChannelRegistrationOutcome {
        return new ApprovedChannelRegistrationService($handler)->registerApproved(
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
}
