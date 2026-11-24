<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\List;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\UseCase\List\ListNickAccounts;
use App\NickServ\Application\UseCase\List\ListNickAccountsHandler;
use App\NickServ\Application\UseCase\List\ListNickAccountsResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListNickAccountsHandler::class)]
#[CoversClass(ListNickAccounts::class)]
#[CoversClass(ListNickAccountsResult::class)]
final class ListNickAccountsHandlerTest extends TestCase
{
    #[Test]
    public function normalizesPageBelowOneAndReturnsMappedNickData(): void
    {
        $registeredAt = new \DateTimeImmutable('2026-01-01 12:00:00 UTC');
        $lastSeenAt = new \DateTimeImmutable('2026-02-03 04:05:00 UTC');
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getNickname')->willReturn('Davidlig');
        $nick->method('getRegisteredAt')->willReturn($registeredAt);
        $nick->method('getLastSeenAt')->willReturn($lastSeenAt);
        $nick->method('getLastConnectIp')->willReturn('203.0.113.7');
        $nick->method('getStatus')->willReturn(NickStatus::Registered);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*avi*')->willReturn(1);
        $repository->expects(self::once())->method('searchByPattern')->with('*avi*', 0, 2)->willReturn([$nick]);

        $result = new ListNickAccountsHandler($repository, 2)->handle(new ListNickAccounts('*avi*', 0));

        self::assertSame('*avi*', $result->pattern);
        self::assertSame(1, $result->page);
        self::assertSame(2, $result->pageSize);
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->entries);
        self::assertSame('Davidlig', $result->entries[0]->nickname);
        self::assertSame($registeredAt, $result->entries[0]->registeredAt);
        self::assertSame($lastSeenAt, $result->entries[0]->lastSeenAt);
        self::assertSame('203.0.113.7', $result->entries[0]->lastConnectIp);
        self::assertSame(NickStatus::Registered, $result->entries[0]->status);
    }

    #[Test]
    public function neverOnlineNickListsItsRegistrationDateAsLastSeen(): void
    {
        // Intended since #172: a never-online nick shows its registration date, not "never seen".
        $registeredAt = new \DateTimeImmutable('2026-01-01 12:00:00 UTC');
        $nick = RegisteredNick::createActive('NeverOnline', 'hash', null, 'en', $registeredAt);
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('countByPattern')->willReturn(1);
        $repository->method('searchByPattern')->willReturn([$nick]);

        $result = new ListNickAccountsHandler($repository, 2)->handle(new ListNickAccounts('*'));

        self::assertCount(1, $result->entries);
        self::assertSame('NeverOnline', $result->entries[0]->nickname);
        self::assertSame($registeredAt, $result->entries[0]->registeredAt);
        self::assertSame($registeredAt, $result->entries[0]->lastSeenAt);
        self::assertNull($result->entries[0]->lastConnectIp);
    }

    #[Test]
    public function searchesRequestedPageAndReturnsTotalCount(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*')->willReturn(5);
        $repository->expects(self::once())->method('searchByPattern')->with('*', 4, 2)->willReturn([]);

        $result = new ListNickAccountsHandler($repository, 2)->handle(new ListNickAccounts('*', 3));

        self::assertSame(3, $result->page);
        self::assertSame(5, $result->total);
        self::assertSame([], $result->entries);
    }

    #[Test]
    public function doesNotQueryAnOutOfRangePage(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*')->willReturn(3);
        $repository->expects(self::never())->method('searchByPattern');

        $result = new ListNickAccountsHandler($repository, 2)->handle(new ListNickAccounts('*', 3));

        self::assertSame(3, $result->page);
        self::assertSame(3, $result->total);
        self::assertSame([], $result->entries);
    }

    #[Test]
    public function guaranteesAtLeastOneResultPerPageForInvalidConfiguration(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*')->willReturn(1);
        $repository->expects(self::once())->method('searchByPattern')->with('*', 0, 1)->willReturn([]);

        $result = new ListNickAccountsHandler($repository, 0)->handle(new ListNickAccounts('*'));

        self::assertSame(1, $result->pageSize);
        self::assertSame(1, $result->page);
    }
}
