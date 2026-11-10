<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\OperatorPermissionCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperatorPermissionCache::class)]
final class OperatorPermissionCacheTest extends TestCase
{
    #[Test]
    public function cachesPermissionSnapshotsByNickAndInvalidatesThem(): void
    {
        $cache = new OperatorPermissionCache();
        $snapshot = ['roleName' => 'ADMIN', 'permissions' => ['operserv.help']];

        self::assertNull($cache->get(42));
        $cache->set(42, $snapshot['roleName'], $snapshot['permissions']);
        self::assertSame($snapshot, $cache->get(42));
        self::assertNull($cache->get(43));

        $cache->invalidate(42);

        self::assertNull($cache->get(42));
    }

    #[Test]
    public function cachesMissingAssignmentsAsDistinctFromCacheMisses(): void
    {
        $cache = new OperatorPermissionCache();
        $cache->set(42, null, []);

        self::assertSame(['roleName' => null, 'permissions' => []], $cache->get(42));
    }
}
