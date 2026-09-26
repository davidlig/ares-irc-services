<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\List;

use App\NickServ\Application\UseCase\List\ListedNickAccount;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListedNickAccount::class)]
final class ListedNickAccountTest extends TestCase
{
    #[Test]
    public function preservesTheNickSummaryAndOptionalConnectionDetails(): void
    {
        $registeredAt = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $lastSeenAt = new DateTimeImmutable('2026-02-03T04:05:00+00:00');
        $account = new ListedNickAccount('Davidlig', $registeredAt, $lastSeenAt, '203.0.113.7', NickStatus::Registered);

        self::assertSame('Davidlig', $account->nickname);
        self::assertSame($registeredAt, $account->registeredAt);
        self::assertSame($lastSeenAt, $account->lastSeenAt);
        self::assertSame('203.0.113.7', $account->lastConnectIp);
        self::assertSame(NickStatus::Registered, $account->status);

        $pendingAccount = new ListedNickAccount('PendingNick', null, null, null, NickStatus::Pending);

        self::assertSame('PendingNick', $pendingAccount->nickname);
        self::assertNull($pendingAccount->registeredAt);
        self::assertNull($pendingAccount->lastSeenAt);
        self::assertNull($pendingAccount->lastConnectIp);
        self::assertSame(NickStatus::Pending, $pendingAccount->status);
    }
}
