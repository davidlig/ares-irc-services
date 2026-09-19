<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

enum ForbiddenVhostAction
{
    case Add;
    case Delete;
    case List;
}
