<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

enum IrcopOutcome
{
    case Added;
    case RoleChanged;
    case Deleted;
    case Listed;
    case AlreadyAssigned;
    case NickNotRegistered;
    case NickNotActive;
    case NotAssigned;
    case RoleNotFound;
    case InvalidRequest;
    case UnknownAction;
}
