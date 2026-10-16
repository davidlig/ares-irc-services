<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\ApproveChannelRegistration;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\Port\Out\ChannelApprovalOutcome;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistration;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationHandler;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationOutcome;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationResult;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Tests\CRegServ\Fake\ArrayRegisteredChannelLookup;
use App\Tests\CRegServ\Fake\RecordingChannelApproval;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_aprobar (modules/cregserv/main.c:577-656): the pending record is not
 * enough on its own, so approval is what actually registers the channel, and a channel ChanServ
 * already holds, a channel with no request in flight, and a request already approved or denied
 * are all refused.
 */
#[CoversClass(ApproveChannelRegistrationHandler::class)]
#[CoversClass(ApproveChannelRegistration::class)]
#[CoversClass(ApproveChannelRegistrationResult::class)]
#[Group('integration')]
final class ApproveChannelRegistrationHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestDoctrineRepository $repository;

    private ArrayRegisteredChannelLookup $channelLookup;

    private RecordingChannelApproval $approvals;

    private ApproveChannelRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->channelLookup = new ArrayRegisteredChannelLookup();
        $this->approvals = new RecordingChannelApproval();
        $this->handler = new ApproveChannelRegistrationHandler(
            $this->repository,
            $this->channelLookup,
            $this->approvals,
        );
    }

    private function command(
        string $channelName = '#SitioChat',
        string $approverNickname = 'NetAdmin',
        DateTimeImmutable $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00'),
    ): ApproveChannelRegistration {
        return new ApproveChannelRegistration(
            channelName: $channelName,
            approverNickname: $approverNickname,
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
    public function approvesAPendingRequestHandingTheRequesterOverToChanServ(): void
    {
        $this->queueRequest();

        $result = $this->handler->handle($this->command());

        self::assertSame(ApproveChannelRegistrationOutcome::Approved, $result->outcome);
        self::assertSame('#SitioChat', $result->channelName);
        self::assertSame('JuanJo_Jaen', $result->founderNickname);

        self::assertCount(1, $this->approvals->calls);
        self::assertSame([
            'channelName' => '#SitioChat',
            'description' => 'Canal de pruebas',
            'requesterAccountId' => 7,
            'approverNickname' => 'NetAdmin',
        ], $this->approvals->calls[0]);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame(ChannelRegistrationStatus::Approved, $stored->getStatus());
        self::assertSame('NetAdmin', $stored->getApprovedByNick());
    }

    #[Test]
    public function refusesAChannelNameWithoutHashPrefix(): void
    {
        $result = $this->handler->handle($this->command(channelName: 'SitioChat'));

        self::assertSame(ApproveChannelRegistrationOutcome::InvalidChannel, $result->outcome);
        self::assertSame([], $this->approvals->calls, 'Nothing must be handed over for an invalid name.');
    }

    #[Test]
    public function reportsNoPendingRequestWhenNothingWasEverAsked(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertSame(ApproveChannelRegistrationOutcome::NoPendingRequest, $result->outcome);
        self::assertSame([], $this->approvals->calls);
    }

    #[Test]
    public function refusesAChannelChanServAlreadyHolds(): void
    {
        $this->queueRequest();
        $this->channelLookup->registered['#sitiochat'] = true;

        $result = $this->handler->handle($this->command());

        self::assertSame(ApproveChannelRegistrationOutcome::AlreadyRegisteredElsewhere, $result->outcome);
        self::assertSame([], $this->approvals->calls, 'Approval must not reach ChanServ for a taken name.');
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

        self::assertSame(ApproveChannelRegistrationOutcome::AlreadyApproved, $result->outcome);
        self::assertSame('JuanJo_Jaen', $result->founderNickname);
        self::assertSame([], $this->approvals->calls);
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

        self::assertSame(ApproveChannelRegistrationOutcome::AlreadyDenied, $result->outcome);
        self::assertSame([], $this->approvals->calls);
    }

    #[Test]
    public function leavesTheRequestPendingWhenTheRegistrationFails(): void
    {
        $this->approvals = new RecordingChannelApproval(ChannelApprovalOutcome::Failed);
        $this->handler = new ApproveChannelRegistrationHandler(
            $this->repository,
            $this->channelLookup,
            $this->approvals,
        );
        $this->queueRequest();

        $result = $this->handler->handle($this->command());

        self::assertSame(ApproveChannelRegistrationOutcome::RegistrationFailed, $result->outcome);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame(ChannelRegistrationStatus::Pending, $stored->getStatus(), 'A failed approval must not mark the request approved.');
    }

    #[Test]
    public function reportsTheNameAsTakenWhenItIsRegisteredBetweenLookupAndApproval(): void
    {
        $this->approvals = new RecordingChannelApproval(ChannelApprovalOutcome::AlreadyRegistered);
        $this->handler = new ApproveChannelRegistrationHandler(
            $this->repository,
            $this->channelLookup,
            $this->approvals,
        );
        $this->queueRequest();

        $result = $this->handler->handle($this->command());

        self::assertSame(ApproveChannelRegistrationOutcome::AlreadyRegisteredElsewhere, $result->outcome);

        $this->flushAndClear();
        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame(ChannelRegistrationStatus::Pending, $stored->getStatus());
    }
}
