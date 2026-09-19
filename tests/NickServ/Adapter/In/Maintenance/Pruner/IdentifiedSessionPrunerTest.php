<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Maintenance\Pruner\IdentifiedSessionPruner;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifiedSessionPruner::class)]
final class IdentifiedSessionPrunerTest extends TestCase
{
    #[Test]
    public function pruneChecksTrackedUidsAndRemovesDisconnectedSessions(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('listConnectedUids');
        $userLookup->expects(self::exactly(2))->method('isConnectedUid')->willReturnCallback(
            static fn (string $uid): bool => 'UID1' === $uid,
        );

        $registry = new IdentifiedSessionRegistry();
        $registry->register('UID1', 'One');
        $registry->register('UID2', 'Two');
        $pruner = new IdentifiedSessionPruner($registry, $userLookup);

        self::assertSame(1, $pruner->prune());
        self::assertSame('One', $registry->findNick('UID1'));
        self::assertNull($registry->findNick('UID2'));
    }
}
