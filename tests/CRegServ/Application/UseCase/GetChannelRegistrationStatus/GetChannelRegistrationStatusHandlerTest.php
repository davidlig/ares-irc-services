<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatus;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusHandler;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusOutcome;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusResult;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\Tests\CRegServ\Fake\ArrayRegisteredChannelLookup;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_estado (modules/cregserv/main.c:534-577): the requester asks for the
 * state of a channel name and gets "already registered", "in process", "denied" or "none".
 */
#[CoversClass(GetChannelRegistrationStatusHandler::class)]
#[CoversClass(GetChannelRegistrationStatus::class)]
#[CoversClass(GetChannelRegistrationStatusResult::class)]
#[Group('integration')]
final class GetChannelRegistrationStatusHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestRepositoryInterface $repository;

    private ArrayRegisteredChannelLookup $channelLookup;

    private GetChannelRegistrationStatusHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->channelLookup = new ArrayRegisteredChannelLookup();
        $this->handler = new GetChannelRegistrationStatusHandler($this->repository, $this->channelLookup);
    }

    #[Test]
    public function reportsRegisteredWhenTheChannelIsAlreadyWithChanServ(): void
    {
        $this->channelLookup->registered['#sitiochat'] = true;

        $result = $this->handler->handle(new GetChannelRegistrationStatus('#SitioChat'));

        self::assertSame(GetChannelRegistrationStatusOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function reportsRegisteredEvenWhenARequestRecordStillExists(): void
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 1,
            requesterNick: 'JuanJo_Jaen',
        );
        $request->approve('OperNick', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($request);
        $this->flushAndClear();

        $this->channelLookup->registered['#sitiochat'] = true;

        $result = $this->handler->handle(new GetChannelRegistrationStatus('#sitiochat'));

        self::assertSame(GetChannelRegistrationStatusOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function reportsRegisteredFromTheApprovedRecordWhenTheChannelLookupLagsBehind(): void
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 1,
            requesterNick: 'JuanJo_Jaen',
        );
        $request->approve('OperNick', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($request);
        $this->flushAndClear();

        $result = $this->handler->handle(new GetChannelRegistrationStatus('#sitiochat'));

        self::assertSame(GetChannelRegistrationStatusOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function reportsPendingWhileTheRequestAwaitsAnOper(): void
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-15 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 1,
            requesterNick: 'JuanJo_Jaen',
        );
        $this->repository->save($request);
        $this->flushAndClear();

        $result = $this->handler->handle(new GetChannelRegistrationStatus('#SITIOCHAT'));

        self::assertSame(GetChannelRegistrationStatusOutcome::Pending, $result->outcome);
        self::assertNotNull($result->requestedAt);
        self::assertSame(
            new DateTimeImmutable('2026-10-15 10:00:00')->getTimestamp(),
            $result->requestedAt->getTimestamp(),
        );
    }

    #[Test]
    public function reportsDeniedWithTheOperReason(): void
    {
        $request = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 1,
            requesterNick: 'JuanJo_Jaen',
        );
        $request->deny('OperNick', 'Canal de spam', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($request);
        $this->flushAndClear();

        $result = $this->handler->handle(new GetChannelRegistrationStatus('#sitiochat'));

        self::assertSame(GetChannelRegistrationStatusOutcome::Denied, $result->outcome);
        self::assertSame('Canal de spam', $result->deniedReason);
    }

    #[Test]
    public function reportsNoRequestWhenTheChannelIsUnknown(): void
    {
        $result = $this->handler->handle(new GetChannelRegistrationStatus('#NoExiste'));

        self::assertSame(GetChannelRegistrationStatusOutcome::NoRequest, $result->outcome);
    }
}
