<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

enum IrcopAction
{
    case Add;
    case Delete;
    case List;
    case Unknown;
}
