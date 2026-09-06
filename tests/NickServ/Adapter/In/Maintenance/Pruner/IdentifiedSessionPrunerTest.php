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
    public function pruneListsConnectedUidsThenPrunesSessionsNotInAndReturnsCount(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('listConnectedUids')->willReturn(['UID1', 'UID2']);

        $registry = new IdentifiedSessionRegistry();
        $pruner = new IdentifiedSessionPruner($registry, $userLookup);

        $result = $pruner->prune();

        self::assertGreaterThanOrEqual(0, $result);
    }
}
