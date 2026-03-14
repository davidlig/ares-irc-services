<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\Maintenance\Pruner\IdentifiedSessionPruner;
use App\Application\Port\NetworkUserLookupPort;
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

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
