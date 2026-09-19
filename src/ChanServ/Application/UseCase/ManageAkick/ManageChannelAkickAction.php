<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

enum ManageChannelAkickAction
{
    case List;
    case Add;
    case Delete;
    case Unknown;
}
