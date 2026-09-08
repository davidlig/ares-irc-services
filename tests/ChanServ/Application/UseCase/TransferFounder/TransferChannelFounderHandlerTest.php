<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\TransferFounder;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderChangeMailSender;
use App\ChanServ\Application\Port\Out\FounderChangeTokenGenerator;
use App\ChanServ\Application\Port\Out\FounderChangeTokenPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
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
use RuntimeException;

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

    /** @return iterable<string, array{?string}> */
    public static function missingFounderEmails(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('missingFounderEmails')]
    public function tokenFlowRequiresCurrentFounderEmail(?string $email): void
    {
        $accounts = $this->accounts($this->target(), new ChanAccountView(10, 'Founder', 'en', email: $email));

        $result = $this->handler(accounts: $accounts)->handle($this->command());

        self::assertSame(TransferFounderOutcome::CurrentFounderWithoutEmail, $result->outcome);
    }

    /** @return iterable<string, array{string, string}> */
    public static function maskedEmails(): iterable
    {
        yield 'normal' => ['founder@example.com', 'fo***@example.com'];
        yield 'single-character prefix' => ['a@example.com', '***@***'];
        yield 'at at start' => ['@example.com', '***@***'];
        yield 'without at' => ['invalid', '***@***'];
    }

    #[Test]
    #[DataProvider('maskedEmails')]
    public function tokenRequestStoresThrottleAndSendsSemanticMail(string $email, string $expectedHint): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $channel = $this->channel();
        $accounts = $this->accounts($this->target(), new ChanAccountView(10, 'Founder', 'en', email: $email));
        $tokens = $this->createMock(FounderChangeTokenPort::class);
        $tokens->expects(self::once())->method('getLastRequestAt')->with(1)->willReturn(null);
        $tokens->expects(self::once())->method('store')->with(1, 20, 'generated-token', $now->modify('+3600 seconds'));
        $tokens->expects(self::once())->method('recordRequest')->with(1);
        $generator = $this->createStub(FounderChangeTokenGenerator::class);
        $generator->method('generate')->willReturn('generated-token');
        $mail = $this->createMock(FounderChangeMailSender::class);
        $mail->expects(self::once())->method('sendFounderChangeToken')->with(
            $email,
            '#test',
            'Target',
            'generated-token',
            'ChanServ',
            'es',
        );

        $result = $this->handler(accounts: $accounts, tokens: $tokens, generator: $generator, mail: $mail)->handle(
            $this->command(channel: $channel, requestedAt: $now),
        );

        self::assertSame(TransferFounderOutcome::TokenSent, $result->outcome);
        self::assertSame($expectedHint, $result->emailHint);
    }

    #[Test]
    public function recentTokenRequestIsThrottled(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('getLastRequestAt')->willReturn($now->modify('-599 seconds'));

        $result = $this->handler(
            accounts: $this->accountsWithFounderEmail(),
            tokens: $tokens,
        )->handle($this->command(requestedAt: $now));

        self::assertSame(TransferFounderOutcome::Throttled, $result->outcome);
    }

    #[Test]
    public function elapsedThrottleAllowsRequest(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('getLastRequestAt')->willReturn($now->modify('-600 seconds'));
        $generator = $this->createStub(FounderChangeTokenGenerator::class);
        $generator->method('generate')->willReturn('token');

        $result = $this->handler(
            accounts: $this->accountsWithFounderEmail(),
            tokens: $tokens,
            generator: $generator,
        )->handle($this->command(requestedAt: $now));

        self::assertSame(TransferFounderOutcome::TokenSent, $result->outcome);
    }

    #[Test]
    public function disabledThrottleIgnoresRecentRequest(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('getLastRequestAt')->willReturn($now);
        $generator = $this->createStub(FounderChangeTokenGenerator::class);
        $generator->method('generate')->willReturn('token');

        $result = $this->handler(
            accounts: $this->accountsWithFounderEmail(),
            tokens: $tokens,
            generator: $generator,
            minInterval: 0,
        )->handle($this->command(requestedAt: $now));

        self::assertSame(TransferFounderOutcome::TokenSent, $result->outcome);
    }

    #[Test]
    public function mailFailureKeepsStoredRequestAndReturnsSemanticError(): void
    {
        $tokens = $this->createMock(FounderChangeTokenPort::class);
        $tokens->expects(self::once())->method('store');
        $tokens->expects(self::once())->method('recordRequest');
        $generator = $this->createStub(FounderChangeTokenGenerator::class);
        $generator->method('generate')->willReturn('secret-token');
        $mail = $this->createStub(FounderChangeMailSender::class);
        $mail->method('sendFounderChangeToken')->willThrowException(new RuntimeException('transport failed'));

        $result = $this->handler(
            accounts: $this->accountsWithFounderEmail(),
            tokens: $tokens,
            generator: $generator,
            mail: $mail,
        )->handle($this->command());

        self::assertSame(TransferFounderOutcome::MailDeliveryFailed, $result->outcome);
    }

    #[Test]
    public function invalidTokenIsRejected(): void
    {
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('consume')->willReturn(null);

        $result = $this->handler(accounts: $this->accountsWithFounderEmail(), tokens: $tokens)->handle(
            $this->command(token: 'wrong-token'),
        );

        self::assertSame(TransferFounderOutcome::InvalidToken, $result->outcome);
    }

    /** @return iterable<string, array{int, ?int, TransferFounderOutcome}> */
    public static function invalidStoredFounderIds(): iterable
    {
        yield 'current founder' => [10, null, TransferFounderOutcome::SameFounder];
        yield 'successor' => [30, 30, TransferFounderOutcome::TargetIsSuccessor];
    }

    #[Test]
    #[DataProvider('invalidStoredFounderIds')]
    public function consumedTokenRevalidatesStoredFounderId(
        int $storedNickId,
        ?int $successorNickId,
        TransferFounderOutcome $expected,
    ): void {
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('consume')->willReturn($storedNickId);

        $result = $this->handler(accounts: $this->accountsWithFounderEmail(), tokens: $tokens)->handle(
            $this->command(channel: $this->channel(successorNickId: $successorNickId), token: 'valid-token'),
        );

        self::assertSame($expected, $result->outcome);
    }

    #[Test]
    public function validTokenWithoutActorIsConsumedButDoesNotTransfer(): void
    {
        $tokens = $this->createMock(FounderChangeTokenPort::class);
        $tokens->expects(self::once())->method('consume')->willReturn(20);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByFounderNickId')->willReturn([]);
        $channels->expects(self::never())->method('save');

        $result = $this->handler(
            channels: $channels,
            accounts: $this->accountsWithFounderEmail(),
            tokens: $tokens,
        )->handle($this->command(token: 'valid-token', actor: null));

        self::assertSame(TransferFounderOutcome::Ignored, $result->outcome);
    }

    #[Test]
    public function validTokenTransfersFounderWithoutAccessAndPublishesOwnerEvent(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $channel = $this->mockChannel();
        $channel->expects(self::once())->method('changeFounder')->with(25);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByFounderNickId')->with(20)->willReturn([]);
        $channels->expects(self::once())->method('save')->with($channel);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::once())->method('findByChannelAndNick')->with(1, 25)->willReturn(null);
        $access->expects(self::never())->method('remove');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($this->target());
        $accounts->method('findAccountById')->willReturnMap([
            [10, new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com')],
            [25, null],
        ]);
        $tokens = $this->createStub(FounderChangeTokenPort::class);
        $tokens->method('consume')->willReturn(25);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelFounderChangedEvent
                && 10 === $event->oldFounderNickId
                && 25 === $event->newFounderNickId
                && 'Actor' === $event->performedBy
                && 99 === $event->performedByNickId
                && '127.0.0.1' === $event->performedByIp
                && 'ident@host.test' === $event->performedByHost
                && !$event->byOperator
                && $now == $event->occurredAt,
        ));

        $result = $this->handler(
            channels: $channels,
            access: $access,
            accounts: $accounts,
            tokens: $tokens,
            events: $events,
        )->handle($this->command(channel: $channel, token: 'valid-token', requestedAt: $now));

        self::assertSame(TransferFounderOutcome::Updated, $result->outcome);
        self::assertSame('25', $result->targetNickname);
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
        $tokens = $this->createMock(FounderChangeTokenPort::class);
        $tokens->expects(self::never())->method('getLastRequestAt');

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
        ?FounderChangeTokenPort $tokens = null,
        ?ChanServEventPublisher $events = null,
        ?FounderChangeTokenGenerator $generator = null,
        ?FounderChangeMailSender $mail = null,
        int $minInterval = 600,
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
            $tokens ?? $this->createStub(FounderChangeTokenPort::class),
            $events ?? $this->createStub(ChanServEventPublisher::class),
            $generator ?? $this->createStub(FounderChangeTokenGenerator::class),
            $mail ?? $this->createStub(FounderChangeMailSender::class),
            new FounderTransferPolicy(),
            3600,
            $minInterval,
            $maxChannels,
        );
    }

    private function command(
        ?RegisteredChannel $channel = null,
        string $targetNickname = 'Target',
        ?string $token = null,
        ?FounderTransferActor $actor = new FounderTransferActor('Actor', 99, '127.0.0.1', 'ident@host.test'),
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

    private function accountsWithFounderEmail(): ChanUserAccountPort
    {
        return $this->accounts(
            $this->target(),
            new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com'),
        );
    }
}
