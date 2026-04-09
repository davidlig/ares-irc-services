<?php

declare(strict_types=1);

namespace App\Tests\Application\Security;

use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionRegistry::class)]
final class PermissionRegistryTest extends TestCase
{
    #[Test]
    public function getAllPermissionsReturnsEmptyArrayWhenNoProviders(): void
    {
        $registry = new PermissionRegistry([]);

        $permissions = $registry->getAllPermissions();

        self::assertSame([], $permissions);
    }

    #[Test]
    public function getAllPermissionsReturnsPermissionsFromSingleProvider(): void
    {
        $provider = self::createProvider('TestService', ['TEST_PERMISSION_1', 'TEST_PERMISSION_2']);
        $registry = new PermissionRegistry([$provider]);

        $permissions = $registry->getAllPermissions();

        self::assertSame(['TEST_PERMISSION_1', 'TEST_PERMISSION_2'], $permissions);
    }

    #[Test]
    public function getAllPermissionsMergesPermissionsFromMultipleProviders(): void
    {
        $provider1 = self::createProvider('Service1', ['PERM_1', 'PERM_2']);
        $provider2 = self::createProvider('Service2', ['PERM_3', 'PERM_4']);
        $registry = new PermissionRegistry([$provider1, $provider2]);

        $permissions = $registry->getAllPermissions();

        self::assertSame(['PERM_1', 'PERM_2', 'PERM_3', 'PERM_4'], $permissions);
    }

    #[Test]
    public function getAllPermissionsRemovesDuplicates(): void
    {
        $provider1 = self::createProvider('Service1', ['PERM_A', 'PERM_B']);
        $provider2 = self::createProvider('Service2', ['PERM_B', 'PERM_C']);
        $registry = new PermissionRegistry([$provider1, $provider2]);

        $permissions = $registry->getAllPermissions();

        self::assertSame(['PERM_A', 'PERM_B', 'PERM_C'], $permissions);
    }

    #[Test]
    public function getAllPermissionsSortsAlphabetically(): void
    {
        $provider = self::createProvider('Service', ['Z_PERMISSION', 'A_PERMISSION', 'M_PERMISSION']);
        $registry = new PermissionRegistry([$provider]);

        $permissions = $registry->getAllPermissions();

        self::assertSame(['A_PERMISSION', 'M_PERMISSION', 'Z_PERMISSION'], $permissions);
    }

    #[Test]
    public function getPermissionsByServiceGroupsByServiceName(): void
    {
        $provider1 = self::createProvider('NickServ', [NickServPermission::DROP]);
        $provider2 = self::createProvider('ChanServ', [ChanServPermission::SUSPEND, ChanServPermission::DROP]);
        $registry = new PermissionRegistry([$provider1, $provider2]);

        $byService = $registry->getPermissionsByService();

        self::assertSame([NickServPermission::DROP], $byService['NickServ']);
        self::assertSame([ChanServPermission::DROP, ChanServPermission::SUSPEND], $byService['ChanServ']);
    }

    #[Test]
    public function getPermissionsByServiceSortsPermissionsAlphabetically(): void
    {
        $provider = self::createProvider('TestService', ['Z_PERM', 'A_PERM', 'M_PERM']);
        $registry = new PermissionRegistry([$provider]);

        $byService = $registry->getPermissionsByService();

        self::assertSame(['A_PERM', 'M_PERM', 'Z_PERM'], $byService['TestService']);
    }

    private static function createProvider(string $serviceName, array $permissions): PermissionProviderInterface
    {
        return new readonly class($serviceName, $permissions) implements PermissionProviderInterface {
            public function __construct(
                private string $serviceName,
                private array $permissions,
            ) {
            }

            public function getServiceName(): string
            {
                return $this->serviceName;
            }

            public function getPermissions(): array
            {
                return $this->permissions;
            }
        };
    }
}
