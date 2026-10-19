<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

enum ManageChannelAkickAction
{
    case List;
    case Add;
    case Delete;
    case Set;
    case Clear;
    case Count;
    case Check;
    case Enforce;
    case View;
    case LastUse;
    case Unknown;
}
