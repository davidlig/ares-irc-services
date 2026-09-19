<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

enum RoleAction
{
    case Add;
    case Delete;
    case List;
    case PermissionList;
    case PermissionAdd;
    case PermissionAddAll;
    case PermissionDelete;
    case PermissionClear;
    case ModesView;
    case ModesSet;
    case VhostView;
    case VhostSet;
    case OperclassList;
    case OperclassView;
    case OperclassSet;
    case Unknown;
}
