<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Security;

use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use App\Irc\Adapter\Out\Security\RegisteredNetworkPermissionCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegisteredNetworkPermissionCatalog::class)]
final class RegisteredNetworkPermissionCatalogTest extends TestCase
{
    #[Test]
    public function exposesTheRegisteredPermissionsAsAList(): void
    {
        $provider = new readonly class implements PermissionProviderInterface {
            public function getServiceName(): string
            {
                return 'OperServ';
            }

            public function getPermissions(): array
            {
                return ['operserv.raw', 'operserv.kill'];
            }
        };

        $catalog = new RegisteredNetworkPermissionCatalog(new PermissionRegistry([$provider]));

        self::assertSame(['operserv.kill', 'operserv.raw'], $catalog->all());
    }
}
