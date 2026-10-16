<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\RegisterChannel;

use App\ChanServ\Adapter\Out\InMemory\ChannelRegisterThrottleRegistry;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRegisterThrottlePort;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelOutcome;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function count;
use function strcasecmp;

/**
 * RegisterChannelHandler is the single registration path behind SAREGISTER and
 * CReG's APROBAR. ChanServ REGISTER no longer reaches it (it redirects to CReG),
 * so these tests exercise the use case at its own layer instead of through the
 * adapter.
 */
#[CoversClass(RegisterChannel::class)]
#[CoversClass(RegisterChannelHandler::class)]
#[CoversClass(RegisterChannelResult::class)]
final class RegisterChannelHandlerTest extends TestCase
{
    private const int MAX_CHANNELS_PER_NICK = 3;

    private const int REGISTER_MIN_INTERVAL_SECONDS = 21600;

    #[Test]
    public function registersTheChannelPersistsItAndDispatchesTheRegisteredEvent(): void
    {
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ChannelRegisteredEvent $event): bool => 7 === $event->channelId
                    && '#Test' === $event->channelName
                    && '#test' === $event->channelNameLower));

        $result = $this->handler(events: $events)->handle($this->command(channelName: '#Test'));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function purgesStaleRowsAndSeedsTheDefaultLevelsForTheNewChannel(): void
    {
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('removeAllForChannel')->with(7);
        $levels->expects(self::exactly(count(ChannelLevel::DEFAULTS)))->method('save');

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::once())->method('deleteByChannelId')->with(7);

        $this->handler(levels: $levels, akicks: $akicks)->handle($this->command());
    }

    #[Test]
    public function normalizesTheChannelNameToLowercase(): void
    {
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->with('#test')->willReturn(false);
        $channels->method('findByFounderNickId')->willReturn([]);
        $channels->method('save')->willReturnCallback($this->assignId(7));

        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ChannelRegisteredEvent $event): bool => '#test' === $event->channelNameLower));

        $this->handler(channels: $channels, events: $events)->handle($this->command(channelName: '#Test'));
    }

    #[Test]
    public function throwsWhenTheChannelIsAlreadyRegistered(): void
    {
        $channels = $this->channels($this->existingChannel());

        $this->expectException(ChannelAlreadyRegisteredException::class);

        $this->handler(channels: $channels)->handle($this->command());
    }

    #[Test]
    public function returnsPendingDeletionWhenTheChannelIsPendingDeletion(): void
    {
        $existing = $this->existingChannel();
        $existing->markPendingDeletion(new DateTimeImmutable('2026-01-01 00:00:00'));

        $result = $this->handler(channels: $this->channels($existing))->handle($this->command());

        self::assertSame(RegisterChannelOutcome::PendingDeletion, $result->outcome);
    }

    #[Test]
    public function rejectsAChannelThatIsNotOnTheNetwork(): void
    {
        $result = $this->handler()->handle($this->command(channelExistsOnNetwork: false));

        self::assertSame(RegisterChannelOutcome::ChannelNotOnNetwork, $result->outcome);
    }

    #[Test]
    public function registersAChannelThatIsNotOnTheNetworkWhenForced(): void
    {
        $result = $this->handler()->handle($this->command(channelExistsOnNetwork: false, force: true));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function returnsNotIdentifiedWithoutAnAccount(): void
    {
        $result = $this->handler()->handle($this->command(accountId: null));

        self::assertSame(RegisterChannelOutcome::NotIdentified, $result->outcome);
    }

    #[Test]
    public function returnsInsufficientRankWithoutChannelRank(): void
    {
        $result = $this->handler()->handle($this->command(hasRequiredChannelRank: false));

        self::assertSame(RegisterChannelOutcome::InsufficientChannelRank, $result->outcome);
    }

    #[Test]
    public function throttlesWhileTheCooldownIsStillRunning(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(1);

        $result = $this->handler(throttle: $throttle)->handle($this->command());

        self::assertSame(RegisterChannelOutcome::Throttled, $result->outcome);
        self::assertGreaterThan(0, $result->remainingMinutes);
        self::assertLessThanOrEqual(360, $result->remainingMinutes);
    }

    #[Test]
    public function registersWhenTheCooldownHasExpired(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();

        $result = $this->handler(throttle: $throttle)->handle($this->command());

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function rejectsANonOperatorOverTheFounderLimit(): void
    {
        $result = $this->handler(channels: $this->channels(founderChannels: $this->founderChannels()))->handle($this->command());

        self::assertSame(RegisterChannelOutcome::FounderLimitExceeded, $result->outcome);
        self::assertSame(self::MAX_CHANNELS_PER_NICK, $result->maximumChannels);
    }

    #[Test]
    public function operatorBypassesThrottleLimitAndRank(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(1);

        $result = $this->handler(
            channels: $this->channels(founderChannels: $this->founderChannels()),
            throttle: $throttle,
        )->handle($this->command(actorIrcOperator: true, hasRequiredChannelRank: false));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function rootBypassesThrottleLimitAndRank(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(1);

        $result = $this->handler(
            channels: $this->channels(founderChannels: $this->founderChannels()),
            throttle: $throttle,
            access: $this->rootAccess('Root'),
        )->handle($this->command(actorNickname: 'Root', hasRequiredChannelRank: false));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function doesNotRecordTheThrottleForPrivilegedActors(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();

        $this->handler(throttle: $throttle)->handle($this->command(actorIrcOperator: true));

        self::assertNull($throttle->getLastRegistrationAt(1));
    }

    #[Test]
    public function recordsTheThrottleForNonPrivilegedActors(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();

        $this->handler(throttle: $throttle)->handle($this->command());

        self::assertNotNull($throttle->getLastRegistrationAt(1));
    }

    #[Test]
    public function scopesTheThrottleToEachAccount(): void
    {
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);

        $result = $this->handler(throttle: $throttle)->handle($this->command(accountId: 11));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    private function handler(
        ?RegisteredChannelRepositoryInterface $channels = null,
        ?ChannelLevelRepositoryInterface $levels = null,
        ?ChannelAkickRepositoryInterface $akicks = null,
        ?ChannelRegisterThrottlePort $throttle = null,
        ?EventBusInterface $events = null,
        ?ChanServOperatorAccess $access = null,
    ): RegisterChannelHandler {
        return new RegisterChannelHandler(
            $channels ?? $this->channels(),
            $levels ?? $this->createStub(ChannelLevelRepositoryInterface::class),
            $akicks ?? $this->createStub(ChannelAkickRepositoryInterface::class),
            $throttle ?? new ChannelRegisterThrottleRegistry(),
            $events ?? $this->createStub(EventBusInterface::class),
            $access ?? $this->access(),
            self::MAX_CHANNELS_PER_NICK,
            self::REGISTER_MIN_INTERVAL_SECONDS,
        );
    }

    private function command(
        string $channelName = '#test',
        ?int $accountId = 1,
        string $actorNickname = 'User',
        bool $actorIrcOperator = false,
        bool $channelExistsOnNetwork = true,
        bool $hasRequiredChannelRank = true,
        bool $force = false,
    ): RegisterChannel {
        return new RegisterChannel(
            channelName: $channelName,
            description: 'A channel',
            accountId: $accountId,
            actorNickname: $actorNickname,
            actorIdentified: null !== $accountId,
            actorIrcOperator: $actorIrcOperator,
            channelExistsOnNetwork: $channelExistsOnNetwork,
            hasRequiredChannelRank: $hasRequiredChannelRank,
            occurredAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            force: $force,
        );
    }

    /**
     * Repository stub whose save() assigns id 7, which is what makes the handler
     * reach the level seeding and the published event.
     *
     * @param list<RegisteredChannel> $founderChannels
     */
    private function channels(?RegisteredChannel $existing = null, array $founderChannels = []): RegisteredChannelRepositoryInterface
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('existsByChannelName')->willReturn(null !== $existing);
        $channels->method('findByChannelName')->willReturn($existing);
        $channels->method('findByFounderNickId')->willReturn($founderChannels);
        $channels->method('save')->willReturnCallback($this->assignId(7));

        return $channels;
    }

    /** @return list<RegisteredChannel> */
    private function founderChannels(): array
    {
        return [
            $this->existingChannel(),
            $this->existingChannel(),
            $this->existingChannel(),
        ];
    }

    private function existingChannel(): RegisteredChannel
    {
        return RegisteredChannel::register(new DateTimeImmutable('2026-01-01 00:00:00'), '#test', 1, 'A channel');
    }

    private function access(): ChanServOperatorAccess
    {
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('isIrcop')->willReturnCallback(
            static fn (string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool => $ircOperator,
        );

        return $access;
    }

    private function rootAccess(string $nick): ChanServOperatorAccess
    {
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('isIrcop')->willReturnCallback(
            static fn (string $nickname): bool => 0 === strcasecmp($nickname, $nick),
        );

        return $access;
    }

    /** @return callable(RegisteredChannel): void */
    private function assignId(int $id): callable
    {
        return static function (RegisteredChannel $channel) use ($id): void {
            new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, $id);
        };
    }
}
