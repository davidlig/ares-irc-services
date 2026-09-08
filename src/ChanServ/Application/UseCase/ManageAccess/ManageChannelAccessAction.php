<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

enum ManageChannelAccessAction
{
    case Unknown;
    case List;
    case Add;
    case Delete;
}
