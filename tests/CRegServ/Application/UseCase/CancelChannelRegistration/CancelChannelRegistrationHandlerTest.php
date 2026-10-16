<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\CancelChannelRegistration;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistration;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationHandler;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationOutcome;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationResult;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pulsar-style cancel semantics: the requester withdraws their own request, but only while
 * it is still pending. A channel with no record, another account's request and an already
 * approved or denied request are all refused, and a successful cancel leaves the channel
 * name free for a fresh REGISTRA.
 */
#[CoversClass(CancelChannelRegistrationHandler::class)]
#[CoversClass(CancelChannelRegistration::class)]
#[CoversClass(CancelChannelRegistrationResult::class)]
#[Group('integration')]
final class CancelChannelRegistrationHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestDoctrineRepository $repository;

    private CancelChannelRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->handler = new CancelChannelRegistrationHandler($this->repository);
    }

    private function command(
        string $channelName = '#SitioChat',
        int $requesterAccountId = 7,
        DateTimeImmutable $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00'),
    ): CancelChannelRegistration {
        return new CancelChannelRegistration(
            channelName: $channelName,
            requesterAccountId: $requesterAccountId,
            occurredAt: $occurredAt,
        );
    }

    private function queueRequest(string $channelName = '#SitioChat', int $requesterAccountId = 7): ChannelRegistrationRequest
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-15 10:00:00'),
            channelName: $channelName,
            requesterAccountId: $requesterAccountId,
            requesterNick: 'JuanJo_Jaen',
            description: 'Canal de pruebas',
        );
        $this->repository->save($request);
        $this->flushAndClear();

        return $request;
    }

    #[Test]
    public function cancelsTheOwnPendingRequestAndFreesTheChannelName(): void
    {
        $this->queueRequest();

        $result = $this->handler->handle($this->command());

        self::assertSame(CancelChannelRegistrationOutcome::Cancelled, $result->outcome);
        self::assertSame('#SitioChat', $result->channel);

        $this->flushAndClear();
        self::assertNull($this->repository->findByChannel('#sitiochat'), 'A cancelled request must leave the channel name free.');
    }

    #[Test]
    public function refusesAChannelNameWithoutHashPrefix(): void
    {
        $result = $this->handler->handle($this->command(channelName: 'SitioChat'));

        self::assertSame(CancelChannelRegistrationOutcome::InvalidChannel, $result->outcome);
    }

    #[Test]
    public function reportsNoRequestWhenNothingWasEverAsked(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertSame(CancelChannelRegistrationOutcome::NoRequest, $result->outcome);
        self::assertSame('#SitioChat', $result->channel);
    }

    #[Test]
    public function refusesARequestOwnedByAnotherAccount(): void
    {
        $this->queueRequest(requesterAccountId: 7);

        $result = $this->handler->handle($this->command(requesterAccountId: 9));

        self::assertSame(CancelChannelRegistrationOutcome::NotOwner, $result->outcome);
        self::assertSame('#SitioChat', $result->channel);

        $this->flushAndClear();
        self::assertNotNull($this->repository->findByChannel('#sitiochat'), 'Someone else\'s request must survive a cancel attempt.');
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

        self::assertSame(CancelChannelRegistrationOutcome::NotPending, $result->outcome);
        self::assertSame('#SitioChat', $result->channel);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertTrue($stored->isApproved(), 'An approved request must not be deleted by CANCEL.');
    }

    #[Test]
    public function refusesAnAlreadyDeniedRequest(): void
    {
        $this->queueRequest();
        $pending = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($pending);
        $pending->deny('NetAdmin', 'No cumple las normas', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $result = $this->handler->handle($this->command());

        self::assertSame(CancelChannelRegistrationOutcome::NotPending, $result->outcome);

        $this->flushAndClear();
        self::assertNotNull($this->repository->findByChannel('#sitiochat'), 'A denied request must stay on record.');
    }
}
