<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\SaregisterChannel;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannel;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelHandler;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelOutcome;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SaregisterChannelHandler::class)]
#[CoversClass(SaregisterChannel::class)]
#[CoversClass(SaregisterChannelResult::class)]
final class SaregisterChannelHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function rejectsInvalidChannelName(): void
    {
        $handler = $this->handler();

        $invalidNames = ['invalid', '', '#chan with spaces', '#chan,comma'];
        foreach ($invalidNames as $name) {
            $result = $handler->handle($this->command(channelName: $name));
            self::assertSame(SaregisterChannelOutcome::InvalidChannelName, $result->outcome);
        }
    }

    #[Test]
    public function rejectsAlreadyRegisteredChannel(): void
    {
        $existing = $this->createStub(RegisteredChannel::class);
        $existing->method('isPendingDeletion')->willReturn(false);
        $existing->method('isRejectedRegistration')->willReturn(false);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('existsByChannelName')->with('#channel')->willReturn(true);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#channel')->willReturn($existing);

        $handler = $this->handler(channelRepository: $channelRepo);
        $result = $handler->handle($this->command(channelName: '#Channel'));

        self::assertSame(SaregisterChannelOutcome::AlreadyRegistered, $result->outcome);
    }

    #[Test]
    public function rejectsChannelWithActiveRejectedRegistrationRestriction(): void
    {
        $existing = $this->createStub(RegisteredChannel::class);
        $existing->method('isPendingDeletion')->willReturn(false);
        $existing->method('isRejectedRegistration')->willReturn(true);
        $existing->method('getRegistrationRestrictedUntil')->willReturn($this->now->modify('+1 day'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('existsByChannelName')->with('#restricted')->willReturn(true);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#restricted')->willReturn($existing);

        $handler = $this->handler(channelRepository: $channelRepo);
        $result = $handler->handle($this->command(channelName: '#restricted'));

        self::assertSame(SaregisterChannelOutcome::AlreadyRegistered, $result->outcome);
    }

    #[Test]
    public function rejectsPendingDeletionChannel(): void
    {
        $existing = $this->createStub(RegisteredChannel::class);
        $existing->method('isPendingDeletion')->willReturn(true);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('existsByChannelName')->with('#channel')->willReturn(true);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#channel')->willReturn($existing);

        $handler = $this->handler(channelRepository: $channelRepo);
        $result = $handler->handle($this->command(channelName: '#Channel'));

        self::assertSame(SaregisterChannelOutcome::PendingDeletion, $result->outcome);
    }

    #[Test]
    public function rejectsTargetNotFound(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('NonExistent')->willReturn(null);

        $handler = $this->handler(accounts: $accounts);
        $result = $handler->handle($this->command(targetNickname: 'NonExistent'));

        self::assertSame(SaregisterChannelOutcome::TargetNotFound, $result->outcome);
    }

    #[Test]
    public function rejectsTargetNotRegistered(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('Unregistered')->willReturn(
            new ChanAccountView(id: 1, nickname: 'Unregistered', language: 'en', registered: false),
        );

        $handler = $this->handler(accounts: $accounts);
        $result = $handler->handle($this->command(targetNickname: 'Unregistered'));

        self::assertSame(SaregisterChannelOutcome::TargetNotRegistered, $result->outcome);
    }

    #[Test]
    public function rejectsTargetSuspended(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('SuspendedUser')->willReturn(
            new ChanAccountView(id: 2, nickname: 'SuspendedUser', language: 'en', registered: true, suspended: true),
        );

        $handler = $this->handler(accounts: $accounts);
        $result = $handler->handle($this->command(targetNickname: 'SuspendedUser'));

        self::assertSame(SaregisterChannelOutcome::TargetSuspended, $result->outcome);
    }

    #[Test]
    public function allowsRegistrationEvenIfTargetFounderHasReachedOrExceededChannelLimit(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('NormalUser')->willReturn(
            new ChanAccountView(id: 10, nickname: 'NormalUser', language: 'en', registered: true, suspended: false),
        );

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::never())->method('findByFounderNickId');
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $chan): void {
            new ReflectionProperty($chan, 'id')->setValue($chan, 77);
        });

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch');

        $handler = $this->handler(
            channelRepository: $channelRepo,
            accounts: $accounts,
            events: $events,
        );

        $result = $handler->handle($this->command(channelName: '#MyChan', targetNickname: 'NormalUser'));
        self::assertSame(SaregisterChannelOutcome::Registered, $result->outcome);
        self::assertSame('#MyChan', $result->channelName);
        self::assertSame('NormalUser', $result->targetNickname);
    }

    #[Test]
    public function registersChannelSuccessfully(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('TargetFounder')->willReturn(
            new ChanAccountView(id: 50, nickname: 'TargetFounder', language: 'en', registered: true, suspended: false),
        );

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $chan): void {
            self::assertSame('#MyChan', $chan->getName());
            self::assertSame(50, $chan->getFounderNickId());
            self::assertSame('Channel description', $chan->getDescription());
            new ReflectionProperty($chan, 'id')->setValue($chan, 123);
        });

        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('removeAllForChannel')->with(123);
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::once())->method('deleteByChannelId')->with(123);
        $activator = new ChannelRegistrationActivator($levels, $akicks);

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(function (object $event): bool {
            return $event instanceof ChannelRegisteredEvent
                && 123 === $event->channelId
                && '#MyChan' === $event->channelName;
        }));

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('save');
        $history = new ChannelHistoryService($historyRepo);

        $handler = $this->handler(
            channelRepository: $channelRepo,
            accounts: $accounts,
            activator: $activator,
            events: $events,
            history: $history,
        );

        $result = $handler->handle($this->command(
            channelName: '#MyChan',
            targetNickname: 'TargetFounder',
            description: 'Channel description',
            actorNickname: 'OperNick',
            actorAccountId: 99,
        ));

        self::assertSame(SaregisterChannelOutcome::Registered, $result->outcome);
        self::assertSame('#MyChan', $result->channelName);
        self::assertSame('TargetFounder', $result->targetNickname);
    }

    #[Test]
    public function replacesExpiredRejectedChannelInTransaction(): void
    {
        $expiredRejected = $this->createStub(RegisteredChannel::class);
        $expiredRejected->method('isPendingDeletion')->willReturn(false);
        $expiredRejected->method('isRejectedRegistration')->willReturn(true);
        $expiredRejected->method('getRegistrationRestrictedUntil')->willReturn($this->now->modify('-1 hour'));
        $expiredRejected->method('getId')->willReturn(555);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $channelRepo->method('findByChannelName')->willReturn($expiredRejected);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('delete')->with($expiredRejected);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $chan): void {
            new ReflectionProperty($chan, 'id')->setValue($chan, 777);
        });

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(
            new ChanAccountView(id: 60, nickname: 'Founder', language: 'en', registered: true, suspended: false),
        );

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch');

        $transaction = new class implements ChanTransactionBoundary {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function afterCommit(callable $operation): void
            {
                $operation();
            }
        };

        $handler = $this->handler(
            channelRepository: $channelRepo,
            accounts: $accounts,
            events: $events,
            transactionBoundary: $transaction,
        );

        $result = $handler->handle($this->command());
        self::assertSame(SaregisterChannelOutcome::Registered, $result->outcome);
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-30T15:00:00Z');
    }

    private function handler(
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
        ?ChanUserAccountPort $accounts = null,
        ?ChannelRegistrationActivator $activator = null,
        ?EventBusInterface $events = null,
        ?ChannelHistoryService $history = null,
        ?ChanTransactionBoundary $transactionBoundary = null,
    ): SaregisterChannelHandler {
        $defaultRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $defaultRepo->method('save')->willReturnCallback(static function (RegisteredChannel $chan): void {
            new ReflectionProperty($chan, 'id')->setValue($chan, 1);
        });

        $defaultAccounts = $this->createStub(ChanUserAccountPort::class);
        $defaultAccounts->method('findAccountByNick')->willReturn(
            new ChanAccountView(id: 1, nickname: 'Alice', language: 'en', registered: true, suspended: false),
        );

        $defaultTx = new class implements ChanTransactionBoundary {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function afterCommit(callable $operation): void
            {
                $operation();
            }
        };

        $defaultActivator = new ChannelRegistrationActivator(
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
        );

        return new SaregisterChannelHandler(
            channelRepository: $channelRepository ?? $defaultRepo,
            accounts: $accounts ?? $defaultAccounts,
            activator: $activator ?? $defaultActivator,
            events: $events ?? $this->createStub(EventBusInterface::class),
            history: $history ?? new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            transactionBoundary: $transactionBoundary ?? $defaultTx,
        );
    }

    private function command(
        string $channelName = '#test',
        string $targetNickname = 'Alice',
        string $description = 'Test channel',
        string $actorNickname = 'OperNick',
        ?int $actorAccountId = 1,
        bool $actorIrcOperator = true,
    ): SaregisterChannel {
        return new SaregisterChannel(
            channelName: $channelName,
            targetNickname: $targetNickname,
            description: $description,
            actorNickname: $actorNickname,
            actorAccountId: $actorAccountId,
            actorIrcOperator: $actorIrcOperator,
            occurredAt: $this->now,
        );
    }
}
