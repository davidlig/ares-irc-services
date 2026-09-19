<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Maintenance\Pruner\SessionLanguagePruner;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLanguagePruner::class)]
final class SessionLanguagePrunerTest extends TestCase
{
    #[Test]
    public function pruneChecksTrackedUidsAndRemovesDisconnectedSessions(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('listConnectedUids');
        $userLookup->expects(self::exactly(2))->method('isConnectedUid')->willReturnCallback(
            static fn (string $uid): bool => 'UID1' === $uid,
        );

        $registry = new SessionLanguageRegistry();
        $registry->register('UID1', 'es');
        $registry->register('UID2', 'de');
        $pruner = new SessionLanguagePruner($registry, $userLookup);

        self::assertSame(1, $pruner->prune());
        self::assertSame('es', $registry->find('UID1'));
        self::assertNull($registry->find('UID2'));
    }
}
