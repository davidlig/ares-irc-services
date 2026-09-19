<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application;

use App\OperServ\Application\IrcopAccessQueryService;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function in_array;

#[CoversClass(IrcopAccessQueryService::class)]
final class IrcopAccessQueryServiceTest extends TestCase
{
    #[Test]
    public function authenticatedRootUsesTheCentralBypassWithoutIrcOperatorStatus(): void
    {
        $query = $this->query(['rootadmin'], [], []);

        self::assertTrue($query->isRoot('RootAdmin', 42, true, false));
        self::assertTrue($query->isIrcop('RootAdmin', 42, true, false));
        self::assertTrue($query->hasPermission('RootAdmin', 42, true, false, 'nickserv.saset'));
    }

    #[Test]
    public function rootNicknameAloneNeverAuthorizesAnUnidentifiedActor(): void
    {
        $query = $this->query(['rootadmin'], [], []);

        self::assertFalse($query->isRoot('RootAdmin', 42, false, true));
        self::assertFalse($query->isIrcop('RootAdmin', 42, false, true));
        self::assertFalse($query->hasPermission('RootAdmin', 42, false, true, 'nickserv.saset'));
        self::assertFalse($query->isRoot('RootAdmin', null, true, true));
    }

    #[Test]
    public function ircOperatorRequiresIdentificationAndAnAssignedRole(): void
    {
        $query = $this->query([], [42], ['nickserv.saset']);

        self::assertFalse($query->isIrcop('OperUser', 42, false, true));
        self::assertFalse($query->isIrcop('OperUser', 42, true, false));
        self::assertFalse($query->isIrcop('OperUser', null, true, true));
        self::assertTrue($query->isIrcop('OperUser', 42, true, true));
        self::assertFalse($this->query([], [], [])->isIrcop('OperUser', 42, true, true));
    }

    #[Test]
    public function permissionsRequireIdentificationIrcOperatorRoleAndExactPermission(): void
    {
        $query = $this->query([], [42], ['nickserv.saset']);

        self::assertFalse($query->hasPermission('OperUser', 42, false, true, 'nickserv.saset'));
        self::assertFalse($query->hasPermission('OperUser', 42, true, false, 'nickserv.saset'));
        self::assertFalse($this->query([], [], ['nickserv.saset'])->hasPermission('OperUser', 42, true, true, 'nickserv.saset'));
        self::assertFalse($query->hasPermission('OperUser', 42, true, true, 'nickserv.drop'));
        self::assertTrue($query->hasPermission('OperUser', 42, true, true, 'nickserv.saset'));
        self::assertTrue($query->hasAnyPermission('OperUser', 42, true, true, ['nickserv.drop', 'nickserv.saset']));
        self::assertFalse($query->hasAnyPermission('OperUser', 42, true, true, []));
    }

    /**
     * @param list<string> $roots
     * @param list<int>    $roleAccountIds
     * @param list<string> $permissions
     */
    private function query(array $roots, array $roleAccountIds, array $permissions): IrcopAccessQueryService
    {
        $rootRegistry = new class($roots) implements RootIdentityRegistry {
            /** @param list<string> $roots */
            public function __construct(private array $roots) {}

            public function contains(string $nickname): bool
            {
                return in_array(strtolower($nickname), $this->roots, true);
            }

            public function allNicknames(): array
            {
                return $this->roots;
            }
        };
        $roleAccess = new class($roleAccountIds, $permissions) implements OperatorRoleAccess {
            /**
             * @param list<int>    $roleAccountIds
             * @param list<string> $permissions
             */
            public function __construct(private array $roleAccountIds, private array $permissions) {}

            public function hasAssignedRole(int $accountId): bool
            {
                return in_array($accountId, $this->roleAccountIds, true);
            }

            public function hasPermission(int $accountId, string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function roleName(int $accountId): ?string
            {
                return $this->hasAssignedRole($accountId) ? 'OPER' : null;
            }
        };
        $rootPolicy = new RootAuthorizationPolicy($rootRegistry);

        return new IrcopAccessQueryService(new OperatorAuthorizationService(
            $rootPolicy,
            new OperatorPermissionPolicy($rootPolicy, $roleAccess),
            $roleAccess,
        ));
    }
}
