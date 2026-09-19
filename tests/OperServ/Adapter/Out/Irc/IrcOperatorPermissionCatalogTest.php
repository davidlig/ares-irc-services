<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkPermissionCatalog;
use App\OperServ\Adapter\Out\Irc\IrcOperatorPermissionCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcOperatorPermissionCatalog::class)]
final class IrcOperatorPermissionCatalogTest extends TestCase
{
    #[Test]
    public function exposesTheNetworkPermissionCatalogUnchanged(): void
    {
        $permissions = $this->createStub(NetworkPermissionCatalog::class);
        $permissions->method('all')->willReturn(['chanserv.drop', 'operserv.raw']);

        self::assertSame(
            ['chanserv.drop', 'operserv.raw'],
            new IrcOperatorPermissionCatalog($permissions)->all(),
        );
    }
}
