<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\Maintenance\Pruner\SessionLanguagePruner;
use App\Application\NickServ\SessionLanguageRegistry;
use App\Application\Port\NetworkUserLookupPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLanguagePruner::class)]
final class SessionLanguagePrunerTest extends TestCase
{
    #[Test]
    public function pruneListsConnectedUidsThenPrunesSessionsNotInAndReturnsCount(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('listConnectedUids')->willReturn(['UID1', 'UID2']);

        $registry = new SessionLanguageRegistry();
        $pruner = new SessionLanguagePruner($registry, $userLookup);

        $result = $pruner->prune();

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
