<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\Security;

use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function in_array;

#[CoversClass(AuthorizationDecision::class)]
#[CoversClass(OperatorActor::class)]
#[CoversClass(OperatorAuthorizationAttribute::class)]
#[CoversClass(OperatorAuthorizationService::class)]
#[CoversClass(OperatorPermissionPolicy::class)]
#[CoversClass(RootAuthorizationPolicy::class)]
final class AuthorizationPolicyTest extends TestCase
{
    #[Test]
    public function rootRequiresConfiguredIdentityAndIdentifiedAccountButNotIrcOperatorStatus(): void
    {
        $service = $this->service(['root'], false, false);

        self::assertSame(
            AuthorizationGrant::RootIdentity,
            $service->root(new OperatorActor('Root', 10, true, false))->grant,
        );
        self::assertFalse($service->root(new OperatorActor('Root', null, true, false))->granted);
        self::assertFalse($service->root(new OperatorActor('Root', 10, false, false))->granted);
        self::assertFalse($service->root(new OperatorActor('Other', 10, true, true))->granted);
    }

    #[Test]
    public function permissionSeparatesIdentificationIrcOperatorRoleAndPermission(): void
    {
        self::assertFalse($this->service([], true, true)->permission(new OperatorActor('Oper', 10, false, true), 'operserv.kill')->granted);
        self::assertFalse($this->service([], true, true)->permission(new OperatorActor('Oper', 10, true, false), 'operserv.kill')->granted);
        self::assertFalse($this->service([], false, true)->permission(new OperatorActor('Oper', 10, true, true), 'operserv.kill')->granted);
        self::assertFalse($this->service([], true, false)->permission(new OperatorActor('Oper', 10, true, true), 'operserv.kill')->granted);

        $decision = $this->service([], true, true)->permission(new OperatorActor('Oper', 10, true, true), 'operserv.kill');
        self::assertTrue($decision->granted);
        self::assertSame(AuthorizationGrant::RolePermission, $decision->grant);
    }

    #[Test]
    public function authenticatedRootBypassesIrcOperatorRoleAndPermission(): void
    {
        $decision = $this->service(['root'], false, false)
            ->permission(new OperatorActor('Root', 10, true, false), 'operserv.kill');

        self::assertTrue($decision->granted);
        self::assertSame(AuthorizationGrant::RootIdentity, $decision->grant);
    }

    #[Test]
    public function identifiedAndIrcOperatorRequirementsRemainIndependent(): void
    {
        $service = $this->service([], true, false);
        $actor = new OperatorActor('Oper', 10, true, true);

        self::assertSame(AuthorizationGrant::IdentifiedAccount, $service->identifiedAccount($actor)->grant);
        self::assertSame(AuthorizationGrant::IrcOperatorStatus, $service->ircOperator($actor)->grant);
        self::assertFalse($service->identifiedAccount(new OperatorActor('Oper', null, true, true))->granted);
        self::assertFalse($service->ircOperator(new OperatorActor('Oper', 10, true, false))->granted);
    }

    #[Test]
    public function ircOperatorAllowsRootAndRejectsAnOperatorWithoutAssignedRole(): void
    {
        self::assertSame(
            AuthorizationGrant::RootIdentity,
            $this->service(['root'], false, false)->ircOperator(new OperatorActor('Root', 10, true, false))->grant,
        );
        self::assertFalse(
            $this->service([], false, false)->ircOperator(new OperatorActor('Oper', 10, true, true))->granted,
        );
    }

    #[Test]
    public function authorizationAttributesAreNonInstantiableConstants(): void
    {
        $reflection = new ReflectionClass(OperatorAuthorizationAttribute::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        $constructor->invoke($instance);
    }

    #[Test]
    public function rootPolicyExposesProtectedConfiguredNicknamesWithoutGrantingAccess(): void
    {
        $registry = $this->rootRegistry(['Root', 'Second']);
        $policy = new RootAuthorizationPolicy($registry);

        self::assertTrue($policy->protectsNickname('root'));
        self::assertSame(['root', 'second'], $policy->protectedNicknames());
        self::assertFalse($policy->allows(new OperatorActor('Root', 10, false, false)));
    }

    /** @param list<string> $roots */
    private function service(array $roots, bool $hasRole, bool $hasPermission): OperatorAuthorizationService
    {
        $root = new RootAuthorizationPolicy($this->rootRegistry($roots));
        $roles = $this->roleAccess($hasRole, $hasPermission);

        return new OperatorAuthorizationService($root, new OperatorPermissionPolicy($root, $roles), $roles);
    }

    /** @param list<string> $roots */
    private function rootRegistry(array $roots): RootIdentityRegistry
    {
        return new class($roots) implements RootIdentityRegistry {
            /** @param list<string> $roots */
            public function __construct(private array $roots) {}

            public function contains(string $nickname): bool
            {
                return in_array(strtolower($nickname), array_map('strtolower', $this->roots), true);
            }

            public function allNicknames(): array
            {
                return array_map('strtolower', $this->roots);
            }
        };
    }

    private function roleAccess(bool $hasRole, bool $hasPermission): OperatorRoleAccess
    {
        return new class($hasRole, $hasPermission) implements OperatorRoleAccess {
            public function __construct(private bool $hasRole, private bool $hasPermission) {}

            public function hasAssignedRole(int $accountId): bool
            {
                return $this->hasRole;
            }

            public function hasPermission(int $accountId, string $permission): bool
            {
                return $this->hasPermission;
            }

            public function roleName(int $accountId): ?string
            {
                return $this->hasRole ? 'OPER' : null;
            }
        };
    }
}
