<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

enum MotdAction
{
    case Add;
    case Delete;
    case List;
    case Clean;
    case Unknown;
}
