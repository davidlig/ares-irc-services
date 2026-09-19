<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

enum AuthorizationGrant: string
{
    case IdentifiedAccount = 'identified_account';
    case IrcOperatorStatus = 'irc_operator_status';
    case RootIdentity = 'root_identity';
    case RolePermission = 'role_permission';
    case ResourceOwnership = 'resource_ownership';
}
