<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

enum RoleOutcome
{
    case Added;
    case Deleted;
    case Listed;
    case AlreadyExists;
    case NotFound;
    case Protected;
    case InvalidRequest;
    case UnknownAction;
    case PermissionsListed;
    case PermissionAdded;
    case PermissionAddedAll;
    case PermissionAlreadyAssigned;
    case PermissionNotFound;
    case PermissionRemoved;
    case PermissionMissing;
    case PermissionsCleared;
    case PermissionsEmpty;
    case ModesViewed;
    case ModesSet;
    case ModesCleared;
    case InvalidModes;
    case ModesNotSupported;
    case VhostViewed;
    case VhostSet;
    case VhostCleared;
    case InvalidVhost;
    case OperclassesListed;
    case OperclassViewed;
    case OperclassSet;
    case OperclassCleared;
    case OperclassNotAvailable;
    case OperclassNotSupported;
}
