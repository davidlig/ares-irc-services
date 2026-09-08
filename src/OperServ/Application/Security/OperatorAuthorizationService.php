<?php

declare(strict_types=1);

namespace App\OperServ\Application\Security;

use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;

final readonly class OperatorAuthorizationService implements OperatorAuthorizationQuery
{
    public function __construct(
        private RootAuthorizationPolicy $rootPolicy,
        private OperatorPermissionPolicy $permissionPolicy,
        private OperatorRoleAccess $roleAccess,
    ) {}

    public function identifiedAccount(OperatorActor $actor): AuthorizationDecision
    {
        if (!$actor->identified || null === $actor->identifiedAccountId) {
            return AuthorizationDecision::denied();
        }

        return AuthorizationDecision::grantedBy(AuthorizationGrant::IdentifiedAccount);
    }

    public function ircOperator(OperatorActor $actor): AuthorizationDecision
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

        return AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus);
    }

    public function root(OperatorActor $actor): AuthorizationDecision
    {
        return $this->rootPolicy->allows($actor)
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity)
            : AuthorizationDecision::denied();
    }

    public function permission(OperatorActor $actor, string $permission): AuthorizationDecision
    {
        return $this->permissionPolicy->decide($actor, $permission);
    }
}
