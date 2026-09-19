<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

enum GlineAction
{
    case Add;
    case Delete;
    case List;
    case Unknown;
}
