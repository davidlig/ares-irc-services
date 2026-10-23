<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\SaregisterChannel;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannel;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelHandler;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelOutcome;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelResult;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function count;

#[CoversClass(SaregisterChannelHandler::class)]
#[CoversClass(SaregisterChannel::class)]
#[CoversClass(SaregisterChannelResult::class)]
final class SaregisterChannelHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-22 10:00:00 UTC');
    }

    #[Test]
    public function registersTheChannelWithTheDesignatedAccountAsFounder(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('existsByChannelName')->with('#room')->willReturn(false);

        $saved = null;
        $channelRepository->expects(self::once())->method('save')->willReturnCallback(
            static function (RegisteredChannel $channel) use (&$saved): void {
                new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 99);
                $saved = $channel;
            },
        );

        $levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepository->expects(self::once())->method('removeAllForChannel');
        $levelRepository->expects(self::exactly(count(ChannelLevel::DEFAULTS)))
            ->method('save');

        $akickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepository->expects(self::once())->method('deleteByChannelId');

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ChannelRegisteredEvent::class));

        $result = $this->handler(
            channelRepository: $channelRepository,
            levelRepository: $levelRepository,
            akickRepository: $akickRepository,
            events: $events,
        )->handle($this->command());

        self::assertSame(SaregisterChannelOutcome::Registered, $result->outcome);
        self::assertSame('#Room', $result->channelName);
        self::assertSame('Founder', $result->founderNickname);
        self::assertInstanceOf(RegisteredChannel::class, $saved);
        self::assertSame('#Room', $saved->getName());
        self::assertSame(42, $saved->getFounderNickId());
        self::assertSame('Migration from another services package', $saved->getDescription());
    }

    #[Test]
    public function rejectsAChannelThatIsAlreadyRegistered(): void
    {
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('existsByChannelName')->willReturn(true);
        $channelRepository->method('findByChannelName')->willReturn(
            RegisteredChannel::register($this->now, '#Room', 7, 'other'),
        );

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::never())->method('dispatch');

        $result = $this->handler(channelRepository: $channelRepository, events: $events)->handle($this->command());

        self::assertSame(SaregisterChannelOutcome::AlreadyRegistered, $result->outcome);
    }

    #[Test]
    public function reportsPendingDeletionInsteadOfOverwritingTheChannel(): void
    {
        $channel = RegisteredChannel::register($this->now, '#Room', 7, 'other');
        $channel->markPendingDeletion($this->now);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('existsByChannelName')->willReturn(true);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $result = $this->handler(channelRepository: $channelRepository)->handle($this->command());

        self::assertSame(SaregisterChannelOutcome::PendingDeletion, $result->outcome);
    }

    #[Test]
    public function rejectsAnUnknownFounderAccount(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(null);

        $result = $this->handler(accounts: $accounts)->handle($this->command());

        self::assertSame(SaregisterChannelOutcome::FounderNotFound, $result->outcome);
    }

    #[Test]
    public function rejectsASuspendedOrUnregisteredFounderAccount(): void
    {
        self::assertSame(
            SaregisterChannelOutcome::FounderInactive,
            $this->handleFounder($this->account(registered: true, suspended: true)),
        );
        self::assertSame(
            SaregisterChannelOutcome::FounderInactive,
            $this->handleFounder($this->account(registered: false, suspended: false)),
        );
    }

    private function handleFounder(ChanAccountView $account): SaregisterChannelOutcome
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($account);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('save');

        return $this->handler(channelRepository: $channelRepository, accounts: $accounts)
            ->handle($this->command())
            ->outcome;
    }

    private function account(bool $registered, bool $suspended): ChanAccountView
    {
        return new ChanAccountView(42, 'Founder', 'en', 'UTC', $registered, $suspended);
    }

    private function command(): SaregisterChannel
    {
        return new SaregisterChannel(
            channelName: '#Room',
            founderNickname: 'Founder',
            description: 'Migration from another services package',
            actorNickname: 'Oper',
            occurredAt: $this->now,
        );
    }

    private function handler(
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
        ?ChannelLevelRepositoryInterface $levelRepository = null,
        ?ChannelAkickRepositoryInterface $akickRepository = null,
        ?ChanUserAccountPort $accounts = null,
        ?EventBusInterface $events = null,
    ): SaregisterChannelHandler {
        if (null === $channelRepository) {
            $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
            $channelRepository->method('existsByChannelName')->willReturn(false);
        }

        if (null === $accounts) {
            $accounts = $this->createStub(ChanUserAccountPort::class);
            $accounts->method('findAccountByNick')->willReturn($this->account(registered: true, suspended: false));
        }

        return new SaregisterChannelHandler(
            $channelRepository,
            $levelRepository ?? $this->createStub(ChannelLevelRepositoryInterface::class),
            $akickRepository ?? $this->createStub(ChannelAkickRepositoryInterface::class),
            $accounts,
            $events ?? $this->createStub(EventBusInterface::class),
        );
    }
}
