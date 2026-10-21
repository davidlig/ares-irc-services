<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\TransferFounder;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderRescueTokenValidator;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenOutcome;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenResult;
use App\ChanServ\Application\UseCase\TransferFounder\FounderTransferActor;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounder;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderHandler;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderResult;
use App\ChanServ\Application\UseCase\TransferFounder\TransferFounderOutcome;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\FounderTransferPolicy;
use App\ChanServ\Domain\ValueObject\FounderTransferDecision;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(TransferChannelFounderHandler::class)]
#[CoversClass(TransferChannelFounder::class)]
#[CoversClass(TransferChannelFounderResult::class)]
#[CoversClass(FounderTransferActor::class)]
#[CoversClass(TransferFounderOutcome::class)]
final class TransferChannelFounderHandlerTest extends TestCase
{
    #[Test]
    public function missingTargetIsRejectedBeforeAccountLookup(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');

        $result = $this->handler(accounts: $accounts)->handle($this->command(targetNickname: '   '));

        self::assertSame(TransferFounderOutcome::MissingTarget, $result->outcome);
    }

    #[Test]
    public function unknownTargetIsRejected(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountByNick')->with('Nobody')->willReturn(null);

        $result = $this->handler(accounts: $accounts)->handle($this->command(targetNickname: ' Nobody '));

        self::assertSame(TransferFounderOutcome::TargetNotFound, $result->outcome);
        self::assertSame('Nobody', $result->targetNickname);
    }

    /**
     * @return iterable<string, array{ChanAccountView, ?int, TransferFounderOutcome}>
     */
    public static function deniedTargets(): iterable
    {
        yield 'suspended' => [new ChanAccountView(20, 'Target', 'en', suspended: true), null, TransferFounderOutcome::TargetSuspended];
        yield 'not registered' => [new ChanAccountView(20, 'Target', 'en', registered: false), null, TransferFounderOutcome::TargetNotRegistered];
        yield 'current founder' => [new ChanAccountView(10, 'Target', 'en'), null, TransferFounderOutcome::SameFounder];
        yield 'successor' => [new ChanAccountView(20, 'Target', 'en'), 20, TransferFounderOutcome::TargetIsSuccessor];
    }

    #[Test]
    #[DataProvider('deniedTargets')]
    public function founderPolicyDenialsAreReturnedBeforeChannelCountLookup(
        ChanAccountView $target,
        ?int $successorNickId,
        TransferFounderOutcome $expected,
    ): void {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($target);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::never())->method('findByFounderNickId');

        $result = $this->handler(channels: $channels, accounts: $accounts)->handle(
            $this->command(channel: $this->channel(successorNickId: $successorNickId)),
        );

        self::assertSame($expected, $result->outcome);
        self::assertSame('Target', $result->targetNickname);
    }

    #[Test]
    public function channelLimitDenialIncludesConfiguredLimit(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($this->target());
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByFounderNickId')->willReturn([
            $this->createStub(RegisteredChannel::class),
            $this->createStub(RegisteredChannel::class),
            $this->createStub(RegisteredChannel::class),
        ]);

        $result = $this->handler(channels: $channels, accounts: $accounts, maxChannels: 3)->handle($this->command());

        self::assertSame(TransferFounderOutcome::ChannelLimitReached, $result->outcome);
        self::assertSame(3, $result->maximumChannelsPerNick);
    }

    #[Test]
    public function missingTokenReturnsInstructionsWithoutGeneratingOrSending(): void
    {
        $validator = $this->createMock(FounderRescueTokenValidator::class);
        $validator->expects(self::never())->method('validate');
        $result = $this->handler(accounts: $this->accounts($this->target()), tokens: $validator)->handle($this->command());
        self::assertSame(TransferFounderOutcome::TokenRequired, $result->outcome);
    }

    /** @return iterable<string, array{FounderRescueTokenOutcome, TransferFounderOutcome}> */
    public static function credentialOutcomes(): iterable
    {
        yield 'invalid' => [FounderRescueTokenOutcome::Invalid, TransferFounderOutcome::InvalidToken];
        yield 'unavailable' => [FounderRescueTokenOutcome::Unavailable, TransferFounderOutcome::CredentialUnavailable];
        yield 'throttled' => [FounderRescueTokenOutcome::Throttled, TransferFounderOutcome::Throttled];
    }

    #[Test]
    #[DataProvider('credentialOutcomes')]
    public function credentialFailuresDoNotTransferOrPublish(FounderRescueTokenOutcome $validation, TransferFounderOutcome $expected): void
    {
        $tokens = $this->createMock(FounderRescueTokenValidator::class);
        $tokens->expects(self::once())->method('validate')->with(10, 'wrong', 'ip:client')->willReturn(new FounderRescueTokenResult($validation, 123));
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByFounderNickId')->willReturn([]);
        $channels->expects(self::never())->method('save');
        $result = $this->handler(channels: $channels, accounts: $this->accounts($this->target()), tokens: $tokens, events: $events)->handle($this->command(token: 'wrong'));
        self::assertSame($expected, $result->outcome);
    }

    #[Test]
    public function tokenBelongsToCurrentFounderNotActorOrTargetAndRemainsReusable(): void
    {
        $channel = $this->mockChannel();
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByFounderNickId')->willReturn([]);
        $channels->expects(self::once())->method('save')->with($channel);
        $tokens = $this->createMock(FounderRescueTokenValidator::class);
        $tokens->expects(self::once())->method('validate')->with(10, 'valid-token', 'ip:client')->willReturn(new FounderRescueTokenResult(FounderRescueTokenOutcome::Valid));
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(static fn (object $event): bool => $event instanceof ChannelFounderChangedEvent && 10 === $event->oldFounderNickId && 20 === $event->newFounderNickId && !$event->byOperator && 'Actor' === $event->performedBy));
        $result = $this->handler(channels: $channels, accounts: $this->accounts($this->target()), tokens: $tokens, events: $events)->handle($this->command(channel: $channel, token: 'valid-token'));
        self::assertSame(TransferFounderOutcome::Updated, $result->outcome);
        self::assertSame('20', $result->targetNickname);
    }

    #[Test]
    public function ordinaryTransferWithoutActorDoesNothing(): void
    {
        $tokens = $this->createMock(FounderRescueTokenValidator::class);
        $tokens->expects(self::never())->method('validate');
        self::assertSame(TransferFounderOutcome::Ignored, $this->handler(accounts: $this->accounts($this->target()), tokens: $tokens)->handle($this->command(token: 'valid', actor: null))->outcome);
    }

    #[Test]
    public function founderEquivalentTransferIsDirectRemovesTargetAccessAndPublishesOperatorEvent(): void
    {
        $channel = $this->mockChannel();
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByFounderNickId')->willReturn([]);
        $channels->expects(self::once())->method('save')->with($channel);
        $entry = $this->createStub(ChannelAccess::class);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($entry);
        $access->expects(self::once())->method('remove')->with($entry);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($this->target());
        $accounts->method('findAccountById')->willReturnMap([[20, $this->target()]]);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelFounderChangedEvent && $event->byOperator,
        ));
        $tokens = $this->createMock(FounderRescueTokenValidator::class);
        $tokens->expects(self::never())->method('validate');

        $result = $this->handler(
            channels: $channels,
            access: $access,
            accounts: $accounts,
            tokens: $tokens,
            events: $events,
        )->handle($this->command(channel: $channel, founderEquivalent: true));

        self::assertSame(TransferFounderOutcome::Updated, $result->outcome);
        self::assertSame('Target', $result->targetNickname);
    }

    #[Test]
    public function founderEquivalentTransferWithoutActorDoesNothingAfterValidation(): void
    {
        $result = $this->handler(accounts: $this->accounts($this->target()))->handle(
            $this->command(actor: null, founderEquivalent: true),
        );

        self::assertSame(TransferFounderOutcome::Ignored, $result->outcome);
    }

    #[Test]
    public function allowedDecisionCannotBePresentedAsADenial(): void
    {
        $method = new ReflectionMethod(TransferChannelFounderHandler::class, 'denied');

        $this->expectException(LogicException::class);

        $method->invoke(
            $this->handler(),
            FounderTransferDecision::Allowed,
            'Target',
        );
    }

    private function handler(
        ?RegisteredChannelRepositoryInterface $channels = null,
        ?ChannelAccessRepositoryInterface $access = null,
        ?ChanUserAccountPort $accounts = null,
        ?FounderRescueTokenValidator $tokens = null,
        ?ChanServEventPublisher $events = null,
        int $maxChannels = 3,
    ): TransferChannelFounderHandler {
        if (null === $channels) {
            $defaultChannels = $this->createStub(RegisteredChannelRepositoryInterface::class);
            $defaultChannels->method('findByFounderNickId')->willReturn([]);
            $channels = $defaultChannels;
        }

        return new TransferChannelFounderHandler(
            $channels,
            $access ?? $this->createStub(ChannelAccessRepositoryInterface::class),
            $accounts ?? $this->createStub(ChanUserAccountPort::class),
            $tokens ?? $this->createStub(FounderRescueTokenValidator::class),
            $events ?? $this->createStub(ChanServEventPublisher::class),
            new FounderTransferPolicy(),
            $maxChannels,
        );
    }

    private function command(
        ?RegisteredChannel $channel = null,
        string $targetNickname = 'Target',
        ?string $token = null,
        ?FounderTransferActor $actor = new FounderTransferActor('Actor', 99, '127.0.0.1', 'ident@host.test', 'ip:client'),
        bool $founderEquivalent = false,
        ?DateTimeImmutable $requestedAt = null,
    ): TransferChannelFounder {
        return new TransferChannelFounder(
            $channel ?? $this->channel(),
            $targetNickname,
            $token,
            $actor,
            $founderEquivalent,
            'ChanServ',
            'es',
            $requestedAt ?? new DateTimeImmutable('2026-09-07 12:00:00 UTC'),
        );
    }

    private function channel(?int $successorNickId = null): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn($successorNickId);

        return $channel;
    }

    /** @return RegisteredChannel&MockObject */
    private function mockChannel(?int $successorNickId = null): RegisteredChannel
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn($successorNickId);

        return $channel;
    }

    private function target(): ChanAccountView
    {
        return new ChanAccountView(20, 'Target', 'en');
    }

    private function accounts(ChanAccountView $target, ?ChanAccountView $currentFounder = null): ChanUserAccountPort
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($target);
        $accounts->method('findAccountById')->willReturn($currentFounder);

        return $accounts;
    }
}
