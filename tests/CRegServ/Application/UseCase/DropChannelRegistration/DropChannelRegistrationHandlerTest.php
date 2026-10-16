<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\DropChannelRegistration;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistration;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationHandler;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationOutcome;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationResult;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Tests\CRegServ\Fake\ArrayRegisteredChannelLookup;
use App\Tests\CRegServ\Fake\RecordingChannelDrop;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_drop (modules/cregserv/main.c:682-699): the record is removed and with
 * it the thing that blocked the channel name. That is the only way out of a denial, so
 * dropping a denied request has to leave the name free for a fresh REGISTRA. In ares the
 * registration lives in ChanServ, so an approved channel is also unregistered there.
 */
#[CoversClass(DropChannelRegistrationHandler::class)]
#[CoversClass(DropChannelRegistration::class)]
#[CoversClass(DropChannelRegistrationResult::class)]
#[Group('integration')]
final class DropChannelRegistrationHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestDoctrineRepository $repository;

    private ArrayRegisteredChannelLookup $channelLookup;

    private RecordingChannelDrop $drops;

    private DropChannelRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->channelLookup = new ArrayRegisteredChannelLookup();
        $this->drops = new RecordingChannelDrop();
        $this->handler = new DropChannelRegistrationHandler(
            $this->repository,
            $this->channelLookup,
            $this->drops,
        );
    }

    private function command(
        string $channelName = '#SitioChat',
        string $actorNickname = 'NetAdmin',
        DateTimeImmutable $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00'),
    ): DropChannelRegistration {
        return new DropChannelRegistration(
            channelName: $channelName,
            actorNickname: $actorNickname,
            occurredAt: $occurredAt,
        );
    }

    private function queue(
        string $channelName = '#SitioChat',
        DateTimeImmutable $requestedAt = new DateTimeImmutable('2026-10-15 10:00:00'),
    ): ChannelRegistrationRequest {
        $request = ChannelRegistrationRequest::request(
            requestedAt: $requestedAt,
            channelName: $channelName,
            requesterAccountId: 7,
            requesterNick: 'JuanJo_Jaen',
            description: 'Canal de pruebas',
        );
        $this->repository->save($request);
        $this->flushAndClear();

        return $request;
    }

    private function assertChannelNameIsFree(): void
    {
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannel('#sitiochat'), 'The channel name must be free again.');
    }

    #[Test]
    public function removesTheRecordAndReportsIt(): void
    {
        $this->queue();

        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::Dropped, $result->outcome);
        self::assertSame('#SitioChat', $result->channelName);

        $this->assertChannelNameIsFree();
    }

    #[Test]
    public function removingAPendingRequestDoesNotTouchChanServ(): void
    {
        $this->queue();

        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::Dropped, $result->outcome);
        self::assertSame([], $this->drops->calls, 'A pending request has no ChanServ registration to remove.');

        $this->assertChannelNameIsFree();
    }

    /**
     * R1-creg-denied-name-blocks: a denial used to block the channel forever, because the
     * unique record was never removed. DROP is the release valve.
     */
    #[Test]
    public function freesANameThatWasBlockedByADenial(): void
    {
        $this->queue();
        $denied = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($denied);
        $denied->deny('NetAdmin', 'Spam', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::Dropped, $result->outcome);
        self::assertSame([], $this->drops->calls, 'A denied request has no ChanServ registration to remove.');

        $this->assertChannelNameIsFree();

        $this->repository->save(ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-16 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 7,
            requesterNick: 'JuanJo_Jaen',
            description: 'Canal de pruebas',
        ));
        $this->flushAndClear();

        $again = $this->repository->findByChannel('#sitiochat');

        self::assertNotNull($again, 'A fresh request must be possible after the denial is dropped.');
        self::assertSame(ChannelRegistrationStatus::Pending, $again->getStatus());
    }

    #[Test]
    public function removesAnApprovedRecordWithoutTouchingChanServWhenItIsNotRegisteredThere(): void
    {
        $this->queue();
        $approved = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($approved);
        $approved->approve('NetAdmin', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::Dropped, $result->outcome, 'pulsar drops any record.');
        self::assertSame([], $this->drops->calls, 'Only CReG holds this record; ChanServ must not be touched.');
        $this->assertChannelNameIsFree();
    }

    #[Test]
    public function unregistersAnApprovedChannelThatChanServHolds(): void
    {
        $this->queue();
        $approved = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($approved);
        $approved->approve('NetAdmin', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $this->channelLookup->registered['#sitiochat'] = true;

        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::DroppedAndUnregistered, $result->outcome);
        self::assertSame('#SitioChat', $result->channelName);

        self::assertCount(1, $this->drops->calls);
        self::assertSame([
            'channelName' => '#SitioChat',
            'actorNickname' => 'NetAdmin',
        ], $this->drops->calls[0]);

        $this->assertChannelNameIsFree();
    }

    #[Test]
    public function refusesAChannelNameWithoutHashPrefix(): void
    {
        $result = $this->handler->handle($this->command(channelName: 'SitioChat'));

        self::assertSame(DropChannelRegistrationOutcome::InvalidChannel, $result->outcome);
        self::assertSame([], $this->drops->calls, 'An invalid name must not reach ChanServ.');
    }

    #[Test]
    public function reportsAChannelCReGDoesNotHold(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertSame(DropChannelRegistrationOutcome::NoRequest, $result->outcome);
        self::assertSame('#SitioChat', $result->channelName);
        self::assertSame([], $this->drops->calls, 'CReG only manages its own channels.');
    }

    #[Test]
    public function dropsARequestThatDiffersOnlyInCase(): void
    {
        $this->queue(channelName: '#SitioChat');

        $result = $this->handler->handle($this->command(channelName: '#SITIOCHAT'));

        self::assertSame(DropChannelRegistrationOutcome::Dropped, $result->outcome);
        $this->assertChannelNameIsFree();
    }
}
