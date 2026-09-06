<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Verify;

use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenConsumer;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandler;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifyNickHandler::class)]
#[CoversClass(VerifyNick::class)]
#[CoversClass(VerifyNickResult::class)]
final class VerifyNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsNoPendingWhenNickNotFound(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('alice')->willReturn(null);

        $handler = new VerifyNickHandler(
            $nickRepo,
            $this->createStub(VerificationTokenConsumer::class),
            $this->createStub(IdentifiedSessionTracker::class),
        );

        $result = $handler->handle(new VerifyNick(nickname: 'alice', token: 'token123', senderUid: 'UID1'));

        self::assertSame(VerifyNickOutcome::NoPending, $result->outcome);
        self::assertNull($result->nickname);
    }

    #[Test]
    public function returnsNoPendingWhenNickIsNotPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('alice')->willReturn($account);

        $handler = new VerifyNickHandler(
            $nickRepo,
            $this->createStub(VerificationTokenConsumer::class),
            $this->createStub(IdentifiedSessionTracker::class),
        );

        $result = $handler->handle(new VerifyNick(nickname: 'alice', token: 'token123', senderUid: 'UID1'));

        self::assertSame(VerifyNickOutcome::NoPending, $result->outcome);
    }

    #[Test]
    public function returnsInvalidTokenWhenTokenConsumerFails(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $tokenConsumer = $this->createMock(VerificationTokenConsumer::class);
        $tokenConsumer->expects(self::once())->method('consume')->with('alice', 'bad-token')->willReturn(false);

        $handler = new VerifyNickHandler(
            $nickRepo,
            $tokenConsumer,
            $this->createStub(IdentifiedSessionTracker::class),
        );

        $result = $handler->handle(new VerifyNick(nickname: 'alice', token: 'bad-token', senderUid: 'UID1'));

        self::assertSame(VerifyNickOutcome::InvalidToken, $result->outcome);
    }

    #[Test]
    public function activatesAccountAndRegistersSessionOnSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getNickname')->willReturn('Alice');
        $account->expects(self::once())->method('activate');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Alice')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $tokenConsumer = $this->createMock(VerificationTokenConsumer::class);
        $tokenConsumer->expects(self::once())->method('consume')->with('Alice', 'good-token')->willReturn(true);

        $sessionTracker = $this->createMock(IdentifiedSessionTracker::class);
        $sessionTracker->expects(self::once())->method('register')->with('UID1', 'Alice');

        $handler = new VerifyNickHandler(
            $nickRepo,
            $tokenConsumer,
            $sessionTracker,
        );

        $result = $handler->handle(new VerifyNick(nickname: 'Alice', token: 'good-token', senderUid: 'UID1'));

        self::assertSame(VerifyNickOutcome::Success, $result->outcome);
        self::assertSame('Alice', $result->nickname);
    }
}
