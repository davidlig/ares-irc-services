<?php

declare(strict_types=1);

namespace App\OperServ\Application\Security;

use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;

final readonly class OperatorPermissionPolicy
{
    public function __construct(
        private RootAuthorizationPolicy $rootPolicy,
        private OperatorRoleAccess $roleAccess,
    ) {}

    public function decide(OperatorActor $actor, string $permission): AuthorizationDecision
    {
        if ($this->rootPolicy->allows($actor)) {
            return AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity);
        }

        if (!$actor->identified || null === $actor->identifiedAccountId || !$actor->ircOperator) {
            return AuthorizationDecision::denied();
        }

        if (!$this->roleAccess->hasAssignedRole($actor->identifiedAccountId)) {
            return AuthorizationDecision::denied();
        }

        if (!$this->roleAccess->hasPermission($actor->identifiedAccountId, $permission)) {
            return AuthorizationDecision::denied();
        }

        return AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission);
    }
}
