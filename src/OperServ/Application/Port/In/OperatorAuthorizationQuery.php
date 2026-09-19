<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

interface OperatorAuthorizationQuery
{
    public function identifiedAccount(OperatorActor $actor): AuthorizationDecision;

    public function ircOperator(OperatorActor $actor): AuthorizationDecision;

    public function root(OperatorActor $actor): AuthorizationDecision;

    public function permission(OperatorActor $actor, string $permission): AuthorizationDecision;
}
