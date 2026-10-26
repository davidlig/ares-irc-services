<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Noexpire;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNick;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickHandler;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickOutcome;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickResult;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
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

        $handler = $this->handler($repository);
        $result = $handler->handle($this->input('Unknown', true));

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

        $handler = $this->handler($repository);
        $result = $handler->handle($this->input('ForbiddenNick', true));

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

        $handler = $this->handler($repository);
        $result = $handler->handle($this->input('SuspendedNick', false));

        self::assertSame(SetNoexpireNickOutcome::Suspended, $result->outcome);
        self::assertSame('SuspendedNick', $result->nickname);
    }

    #[Test]
    public function updatesNoexpireAndSavesAccount(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->expects(self::exactly(2))->method('changeNoExpire')->with(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByNick')->with('ActiveNick')->willReturn($account);
        $repository->expects(self::exactly(2))->method('save')->with($account);

        $historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepository->expects(self::exactly(2))->method('save')->with(self::callback(static fn (NickHistory $entry): bool => 42 === $entry->getNickId()
            && 'NOEXPIRE_ON' === $entry->getAction()
            && 'Oper' === $entry->getPerformedBy()
            && null === $entry->getPerformedByNickId()
            && [] === $entry->getExtraData()));
        $account->method('getId')->willReturn(42);
        $handler = new SetNoexpireNickHandler($repository, new NickHistoryService($historyRepository));
        $result = $handler->handle(new SetNoexpireNick('ActiveNick', true, new DateTimeImmutable('2026-09-06 UTC'), 'Oper'));
        $repeatResult = $handler->handle(new SetNoexpireNick('ActiveNick', true, new DateTimeImmutable('2026-09-06 UTC'), 'Oper'));

        self::assertSame(SetNoexpireNickOutcome::Success, $result->outcome);
        self::assertSame('ActiveNick', $result->nickname);
        self::assertTrue($result->noexpire);
        self::assertSame(SetNoexpireNickOutcome::Success, $repeatResult->outcome);
    }

    private function input(string $nickname, bool $noexpire): SetNoexpireNick
    {
        return new SetNoexpireNick($nickname, $noexpire, new DateTimeImmutable('2026-09-06 UTC'), 'Oper');
    }

    private function handler(RegisteredNickRepositoryInterface $repository): SetNoexpireNickHandler
    {
        return new SetNoexpireNickHandler($repository, new NickHistoryService($this->createStub(NickHistoryRepositoryInterface::class)));
    }
}
