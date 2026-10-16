<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\RequestChannelRegistration;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistration;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationHandler;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationOutcome;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\Exception\ChannelRegistrationConflictException;
use App\Tests\CRegServ\Fake\ArrayRegisteredChannelLookup;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_registra (modules/cregserv/main.c:440-531): a request is queued in the
 * services database and the channel is only registered once a services oper approves it.
 */
#[CoversClass(RequestChannelRegistrationHandler::class)]
#[Group('integration')]
final class RequestChannelRegistrationHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestRepositoryInterface $repository;

    private ArrayRegisteredChannelLookup $channelLookup;

    private RequestChannelRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->channelLookup = new ArrayRegisteredChannelLookup();
        $this->handler = new RequestChannelRegistrationHandler(
            $this->repository,
            $this->channelLookup,
        );
    }

    private function command(
        string $channelName = '#SitioChat',
        string $description = 'Canal de pruebas',
        int $requesterAccountId = 1,
        string $requesterNickname = 'JuanJo_Jaen',
        bool $actorIrcOperator = false,
        DateTimeImmutable $occurredAt = new DateTimeImmutable('2026-10-15 10:00:00'),
    ): RequestChannelRegistration {
        return new RequestChannelRegistration(
            channelName: $channelName,
            description: $description,
            requesterAccountId: $requesterAccountId,
            requesterNickname: $requesterNickname,
            actorIrcOperator: $actorIrcOperator,
            occurredAt: $occurredAt,
        );
    }

    #[Test]
    public function queuesAPendingRequestAndReportsRequested(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);

        $this->flushAndClear();

        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertTrue($stored->isPending());
        self::assertSame('JuanJo_Jaen', $stored->getRequesterNick());
        self::assertSame(1, $stored->getRequesterAccountId());
    }

    #[Test]
    public function storesTheDescriptionSoAPROBARCanHandItOverToRegisterChannel(): void
    {
        $this->handler->handle($this->command(description: 'Canal de la comunidad'));
        $this->flushAndClear();

        $stored = $this->repository->findByChannel('#sitiochat');

        self::assertNotNull($stored);
        self::assertSame('Canal de la comunidad', $stored->getDescription());
    }

    #[Test]
    public function rejectsAChannelNameThatDoesNotStartWithHash(): void
    {
        $result = $this->handler->handle($this->command(channelName: '&SitioChat'));

        self::assertSame(RequestChannelRegistrationOutcome::InvalidChannel, $result->outcome);

        $this->flushAndClear();

        self::assertNull($this->repository->findByChannel('&sitiochat'));
    }

    #[Test]
    public function rejectsAChannelAlreadyRegisteredWithChanServ(): void
    {
        $this->channelLookup->registered['#sitiochat'] = true;

        $result = $this->handler->handle($this->command());

        self::assertSame(RequestChannelRegistrationOutcome::AlreadyRegistered, $result->outcome);

        $this->flushAndClear();

        self::assertNull($this->repository->findByChannel('#sitiochat'));
    }

    #[Test]
    public function rejectsADuplicatePendingRequestForTheSameChannel(): void
    {
        $this->handler->handle($this->command());
        $this->flushAndClear();

        $result = $this->handler->handle($this->command(requesterAccountId: 2, requesterNickname: 'OtroNick'));

        self::assertSame(RequestChannelRegistrationOutcome::DuplicateRequest, $result->outcome);

        $this->flushAndClear();

        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame('JuanJo_Jaen', $stored->getRequesterNick());
    }

    #[Test]
    public function rejectsWhenTheChannelWasAlreadyDenied(): void
    {
        $existing = $this->repository->findByChannel('#sitiochat');
        self::assertNull($existing);

        $denied = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 10:00:00'),
            channelName: '#SitioChat',
            requesterAccountId: 9,
            requesterNick: 'Anterior',
        );
        $denied->deny('OperNick', 'Canal de spam', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($denied);
        $this->flushAndClear();

        $result = $this->handler->handle($this->command());

        self::assertSame(RequestChannelRegistrationOutcome::RequestDenied, $result->outcome);

        $this->flushAndClear();

        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);
        self::assertSame('Anterior', $stored->getRequesterNick());
    }

    #[Test]
    public function throttlesANewRequestInsideTheCooldownWindow(): void
    {
        $this->handler->handle($this->command(occurredAt: new DateTimeImmutable('2026-10-15 10:00:00')));
        $this->flushAndClear();

        $result = $this->handler->handle(
            $this->command(
                channelName: '#OtroCanal',
                actorIrcOperator: false,
                occurredAt: new DateTimeImmutable('2026-10-15 12:00:00'),
            ),
        );

        self::assertSame(RequestChannelRegistrationOutcome::Throttled, $result->outcome);
        self::assertNotNull($result->remainingMinutes);
        self::assertSame(1320, $result->remainingMinutes);

        $this->flushAndClear();

        self::assertNull($this->repository->findByChannel('#otrocanal'));
    }

    #[Test]
    public function exemptsServicesOperatorsFromTheThrottle(): void
    {
        $this->handler->handle($this->command(occurredAt: new DateTimeImmutable('2026-10-15 10:00:00')));
        $this->flushAndClear();

        $result = $this->handler->handle(
            $this->command(
                channelName: '#OtroCanal',
                actorIrcOperator: true,
                occurredAt: new DateTimeImmutable('2026-10-15 10:05:00'),
            ),
        );

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);
    }

    #[Test]
    public function acceptsANewRequestOnceTheCooldownExpired(): void
    {
        $this->handler->handle($this->command(occurredAt: new DateTimeImmutable('2026-10-15 10:00:00')));
        $this->flushAndClear();

        $result = $this->handler->handle(
            $this->command(
                channelName: '#OtroCanal',
                occurredAt: new DateTimeImmutable('2026-10-16 10:00:01'),
            ),
        );

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);
    }

    #[Test]
    public function throttlesOnlyTheSameRequesterAccount(): void
    {
        $this->handler->handle($this->command(occurredAt: new DateTimeImmutable('2026-10-15 10:00:00')));
        $this->flushAndClear();

        $result = $this->handler->handle(
            $this->command(
                channelName: '#OtroCanal',
                requesterAccountId: 77,
                requesterNickname: 'OtraCuenta',
                occurredAt: new DateTimeImmutable('2026-10-15 10:05:00'),
            ),
        );

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);
    }

    /**
     * R3-1 / R1-creg-description-length: the columns hold 64 and 255 characters and IRC hands
     * the input over unbounded, so the row must never be built from input the column cannot hold.
     */
    #[Test]
    public function acceptsAChannelNameExactlyAsLongAsTheColumn(): void
    {
        $channel = '#' . str_repeat('a', 63);

        $result = $this->handler->handle($this->command(channelName: $channel));

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);
    }

    #[Test]
    public function rejectsAChannelNameTheColumnCannotHold(): void
    {
        $channel = '#' . str_repeat('a', 64);

        $result = $this->handler->handle($this->command(channelName: $channel));

        self::assertSame(RequestChannelRegistrationOutcome::ChannelTooLong, $result->outcome);
        $this->assertNoRequestWasStored();
    }

    #[Test]
    public function acceptsADescriptionExactlyAsLongAsTheColumn(): void
    {
        $result = $this->handler->handle($this->command(description: str_repeat('x', 255)));

        self::assertSame(RequestChannelRegistrationOutcome::Requested, $result->outcome);
    }

    #[Test]
    public function rejectsADescriptionTheColumnCannotHold(): void
    {
        $result = $this->handler->handle($this->command(description: str_repeat('x', 256)));

        self::assertSame(RequestChannelRegistrationOutcome::DescriptionTooLong, $result->outcome);
        $this->assertNoRequestWasStored();
    }

    /**
     * R3-2: the duplicate guard is read-then-write, so two requests racing for the same channel
     * both get past it and the loser hits the unique index. That is a duplicate, not a crash.
     */
    #[Test]
    public function reportsADuplicateWhenTheUniqueIndexIsLostAsARace(): void
    {
        $repository = $this->createStub(ChannelRegistrationRequestRepositoryInterface::class);
        $repository->method('findByChannel')->willReturn(null);
        $repository->method('save')
            ->willThrowException(ChannelRegistrationConflictException::forChannel('#sitiochat'));

        $handler = new RequestChannelRegistrationHandler($repository, $this->channelLookup);

        $result = $handler->handle($this->command(actorIrcOperator: true));

        self::assertSame(RequestChannelRegistrationOutcome::DuplicateRequest, $result->outcome);
    }

    private function assertNoRequestWasStored(): void
    {
        $this->flushAndClear();

        $stored = $this->entityManager->getRepository(ChannelRegistrationRequest::class)->count([]);

        self::assertSame(0, $stored, 'Rejected input must never reach the table.');
    }
}
