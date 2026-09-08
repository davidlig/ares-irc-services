<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In\Audit;

enum CommandAuditCategory: string
{
    case OperatorAction = 'operator_action';
    case RootAdministration = 'root_administration';
    case ResourceOverride = 'resource_override';
    case SystemAction = 'system_action';
}
