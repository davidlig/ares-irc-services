<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Noexpire;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNick;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickHandler;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickOutcome;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetNoexpireNickHandler::class)]
#[CoversClass(SetNoexpireNick::class)]
#[CoversClass(SetNoexpireNickResult::class)]
final class SetNoexpireNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsNotRegisteredWhenAccountNotFound(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);

        $handler = new SetNoexpireNickHandler($repository);
        $result = $handler->handle(new SetNoexpireNick(nickname: 'Unknown', noexpire: true));

        self::assertSame(SetNoexpireNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('Unknown', $result->nickname);
    }

    #[Test]
    public function returnsForbiddenWhenAccountIsForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isForbidden')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ForbiddenNick')->willReturn($account);

        $handler = new SetNoexpireNickHandler($repository);
        $result = $handler->handle(new SetNoexpireNick(nickname: 'ForbiddenNick', noexpire: true));

        self::assertSame(SetNoexpireNickOutcome::Forbidden, $result->outcome);
        self::assertSame('ForbiddenNick', $result->nickname);
    }

    #[Test]
    public function returnsSuspendedWhenAccountIsSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('SuspendedNick')->willReturn($account);

        $handler = new SetNoexpireNickHandler($repository);
        $result = $handler->handle(new SetNoexpireNick(nickname: 'SuspendedNick', noexpire: false));

        self::assertSame(SetNoexpireNickOutcome::Suspended, $result->outcome);
        self::assertSame('SuspendedNick', $result->nickname);
    }

    #[Test]
    public function updatesNoexpireAndSavesAccount(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->expects(self::once())->method('changeNoExpire')->with(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ActiveNick')->willReturn($account);
        $repository->expects(self::once())->method('save')->with($account);

        $handler = new SetNoexpireNickHandler($repository);
        $result = $handler->handle(new SetNoexpireNick(nickname: 'ActiveNick', noexpire: true));

        self::assertSame(SetNoexpireNickOutcome::Success, $result->outcome);
        self::assertSame('ActiveNick', $result->nickname);
        self::assertTrue($result->noexpire);
    }
}
