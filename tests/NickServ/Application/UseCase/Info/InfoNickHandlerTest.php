<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Info;

use App\NickServ\Application\Port\Out\AssociatedChannel;
use App\NickServ\Application\Port\Out\NickAssociatedChannelsPort;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\Info\InfoNick;
use App\NickServ\Application\UseCase\Info\InfoNickHandler;
use App\NickServ\Application\UseCase\Info\InfoNickOutcome;
use App\NickServ\Application\UseCase\Info\InfoNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfoNickHandler::class)]
#[CoversClass(InfoNick::class)]
#[CoversClass(InfoNickResult::class)]
final class InfoNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 16:00:00 UTC');
    }

    #[Test]
    public function returnsNotRegisteredWhenNotFound(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('missing')->willReturn(null);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new InfoNick('missing', null, false, false, false));

        self::assertSame(InfoNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('missing', $result->nickname);
    }

    #[Test]
    public function returnsNotRegisteredWhenPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new InfoNick('pendinguser', null, false, false, false));

        self::assertSame(InfoNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('pendinguser', $result->nickname);
    }

    #[Test]
    public function returnsForbiddenWhenForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);
        $account->method('getNickname')->willReturn('badguy');
        $account->method('getReason')->willReturn('Banned');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new InfoNick('badguy', null, false, false, false));

        self::assertSame(InfoNickOutcome::Forbidden, $result->outcome);
        self::assertSame('badguy', $result->nickname);
        self::assertSame('Banned', $result->reason);
    }

    #[Test]
    public function returnsPendingDeletionWhenPendingDeletion(): void
    {
        $deletedAt = $this->now->modify('-1 day');
        $expiresAt = $this->now->modify('+6 days');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(true);
        $account->method('getNickname')->willReturn('retiring');
        $account->method('getPendingDeletionAt')->willReturn($deletedAt);
        $account->method('getPendingDeletionExpiresAt')->willReturn($expiresAt);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = $this->createHandler(nickRepo: $nickRepo, dropGraceDays: 7);
        $result = $handler->handle(new InfoNick('retiring', null, false, false, false));

        self::assertSame(InfoNickOutcome::PendingDeletion, $result->outcome);
        self::assertSame('retiring', $result->nickname);
        self::assertSame($deletedAt, $result->pendingDeletionAt);
        self::assertSame($expiresAt, $result->deletionExpiresAt);
    }

    #[Test]
    public function returnsPrivateWhenAccountIsPrivateAndSenderIsNotOwner(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isPrivate')->willReturn(true);
        $account->method('getNickname')->willReturn('secretive');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new InfoNick('secretive', 'otheruser', false, false, false));

        self::assertSame(InfoNickOutcome::Private, $result->outcome);
        self::assertSame('secretive', $result->nickname);
    }

    #[Test]
    public function returnsVisibleWithLimitedFieldsForPublicView(): void
    {
        $regAt = $this->now->modify('-30 days');
        $seenAt = $this->now->modify('-1 hour');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('alice');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn($regAt);
        $account->method('getLastSeenAt')->willReturn($seenAt);
        $account->method('getLastQuitMessage')->willReturn('Quit: Bye');
        $account->method('getLastConnectIp')->willReturn('1.2.3.4');
        $account->method('getLastConnectHost')->willReturn('host.example.com');
        $account->method('getLanguage')->willReturn('es');
        $account->method('getEmail')->willReturn('alice@example.com');
        $account->method('getVhost')->willReturn('custom.vhost');
        $account->method('isNoExpire')->willReturn(false);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $channelsPort = $this->createMock(NickAssociatedChannelsPort::class);
        $channelsPort->expects(self::never())->method('findChannelsForNick');

        $handler = $this->createHandler(nickRepo: $nickRepo, channelsPort: $channelsPort);
        $result = $handler->handle(new InfoNick('alice', 'stranger', false, false, false));

        self::assertSame(InfoNickOutcome::Visible, $result->outcome);
        self::assertSame('alice', $result->nickname);
        self::assertSame(NickStatus::Registered, $result->status);
        self::assertNull($result->suspendedReason);
        self::assertNull($result->suspendedUntil);
        self::assertSame($regAt, $result->registeredAt);
        self::assertFalse($result->lastSeenOnline);
        self::assertSame($seenAt, $result->lastSeenAt);
        self::assertSame('Quit: Bye', $result->lastQuitMessage);
        self::assertNull($result->lastConnectIp);
        self::assertNull($result->lastConnectHost);
        self::assertSame('es', $result->language);
        self::assertNull($result->email);
        self::assertSame('custom.vhost', $result->displayVhost);
        self::assertFalse($result->isNoExpire);
        self::assertFalse($result->isOwnerIdentified);
        self::assertSame([], $result->channels);
    }

    #[Test]
    public function returnsFullDetailsWhenOwnerIdentified(): void
    {
        $suspendedUntil = $this->now->modify('+1 day');
        $channel = new AssociatedChannel('#general', 'founder', null);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isPrivate')->willReturn(true); // private, but owner is viewing
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('isSuspended')->willReturn(true);
        $account->method('getReason')->willReturn('Suspended reason');
        $account->method('getSuspendedUntil')->willReturn($suspendedUntil);
        $account->method('getRegisteredAt')->willReturn($this->now);
        $account->method('getLastConnectIp')->willReturn(null); // fallback to '*'
        $account->method('getLastConnectHost')->willReturn('alice.isp');
        $account->method('getLanguage')->willReturn('en');
        $account->method('getEmail')->willReturn('alice@example.com');
        $account->method('getVhost')->willReturn('');
        $account->method('isNoExpire')->willReturn(true);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $channelsPort = $this->createMock(NickAssociatedChannelsPort::class);
        $channelsPort->expects(self::once())->method('findChannelsForNick')->with(42)->willReturn([$channel]);

        $handler = $this->createHandler(nickRepo: $nickRepo, channelsPort: $channelsPort);
        $result = $handler->handle(new InfoNick('alice', 'Alice', true, false, true));

        self::assertSame(InfoNickOutcome::Visible, $result->outcome);
        self::assertSame('Alice', $result->nickname);
        self::assertSame(NickStatus::Suspended, $result->status);
        self::assertSame('Suspended reason', $result->suspendedReason);
        self::assertSame($suspendedUntil, $result->suspendedUntil);
        self::assertTrue($result->lastSeenOnline);
        self::assertNull($result->lastSeenAt);
        self::assertSame('*', $result->lastConnectIp);
        self::assertSame('alice.isp', $result->lastConnectHost);
        self::assertSame('alice@example.com', $result->email);
        self::assertSame('', $result->displayVhost);
        self::assertTrue($result->isNoExpire);
        self::assertTrue($result->isOwnerIdentified);
        self::assertSame([$channel], $result->channels);
    }

    #[Test]
    public function returnsConnectionInfoForOperViewingOtherUser(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(99);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('bob');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn($this->now);
        $account->method('getLastConnectIp')->willReturn('9.9.9.9');
        $account->method('getLastConnectHost')->willReturn(null); // fallback to '*'

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $channelsPort = $this->createMock(NickAssociatedChannelsPort::class);
        $channelsPort->expects(self::never())->method('findChannelsForNick');

        $handler = $this->createHandler(nickRepo: $nickRepo, channelsPort: $channelsPort);
        $result = $handler->handle(new InfoNick('bob', 'operuser', true, true, false));

        self::assertSame(InfoNickOutcome::Visible, $result->outcome);
        self::assertSame('9.9.9.9', $result->lastConnectIp);
        self::assertSame('*', $result->lastConnectHost);
        self::assertNull($result->email); // Oper cannot see email
        self::assertSame([], $result->channels); // Oper cannot see channels
        self::assertFalse($result->isOwnerIdentified);
    }

    private function createHandler(
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?NickAssociatedChannelsPort $channelsPort = null,
        int $dropGraceDays = 7,
    ): InfoNickHandler {
        return new InfoNickHandler(
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelsPort ?? $this->createStub(NickAssociatedChannelsPort::class),
            new VhostDisplayResolver(),
            $dropGraceDays,
        );
    }
}
