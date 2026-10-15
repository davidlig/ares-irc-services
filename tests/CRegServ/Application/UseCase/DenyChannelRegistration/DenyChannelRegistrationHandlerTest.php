<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\DenyChannelRegistration;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistration;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationHandler;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationOutcome;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_denegar (modules/cregserv/main.c:656-681): the denial records who denied
 * it, when and why, and refuses a channel with no request in flight, one already approved and
 * one already denied. The channel name stays free afterwards.
 */
#[CoversClass(DenyChannelRegistrationHandler::class)]
#[Group('integration')]
final class DenyChannelRegistrationHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestDoctrineRepository $repository;

    private DenyChannelRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->handler = new DenyChannelRegistrationHandler($this->repository);
    }

    private function command(
        string $channelName = '#SitioChat',
        string $reason = 'No cumple las normas del servicio',
        string $denierNickname = 'NetAdmin',
        DateTimeImmutable $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00'),
    ): DenyChannelRegistration {
        return new DenyChannelRegistration(
            channelName: $channelName,
            reason: $reason,
            denierNickname: $denierNickname,
            occurredAt: $occurredAt,
        );
    }

    private function queueRequest(string $channelName = '#SitioChat'): ChannelRegistrationRequest
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-15 10:00:00'),
            channelName: $channelName,
            requesterAccountId: 7,
            requesterNick: 'JuanJo_Jaen',
            description: 'Canal de pruebas',
        );
        $this->repository->save($request);
        $this->flushAndClear();

        return $request;
    }

    #[Test]
    public function deniesAPendingRequestStoringWhoWhenAndWhy(): void
    {
        $this->queueRequest();

        $result = $this->handler->handle($this->command());

        self::assertSame(DenyChannelRegistrationOutcome::Denied, $result->outcome);
        self::assertSame('#SitioChat', $result->channelName);
        self::assertSame('No cumple las normas del servicio', $result->reason);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame(ChannelRegistrationStatus::Denied, $stored->getStatus());
        self::assertSame('NetAdmin', $stored->getDeniedByNick());
        self::assertSame('No cumple las normas del servicio', $stored->getDeniedReason());
        self::assertNotNull($stored->getDeniedAt());
    }

    #[Test]
    public function refusesAChannelNameWithoutHashPrefix(): void
    {
        $result = $this->handler->handle($this->command(channelName: 'SitioChat'));

        self::assertSame(DenyChannelRegistrationOutcome::InvalidChannel, $result->outcome);
    }

    #[Test]
    public function reportsNoPendingRequestWhenNothingWasEverAsked(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertSame(DenyChannelRegistrationOutcome::NoPendingRequest, $result->outcome);
    }

    #[Test]
    public function refusesAnAlreadyApprovedRequest(): void
    {
        $this->queueRequest();
        $pending = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($pending);
        $pending->approve('NetAdmin', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $result = $this->handler->handle($this->command());

        self::assertSame(DenyChannelRegistrationOutcome::AlreadyApproved, $result->outcome);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame(ChannelRegistrationStatus::Approved, $stored->getStatus(), 'An approved channel must not become denied.');
    }

    #[Test]
    public function refusesAnAlreadyDeniedRequestAndKeepsTheOriginalReason(): void
    {
        $this->queueRequest();
        $pending = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($pending);
        $pending->deny('NetAdmin', 'Motivo original', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $result = $this->handler->handle($this->command(reason: 'Otro motivo'));

        self::assertSame(DenyChannelRegistrationOutcome::AlreadyDenied, $result->outcome);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame('Motivo original', $stored->getDeniedReason(), 'The first denial keeps its reason.');
    }
}
