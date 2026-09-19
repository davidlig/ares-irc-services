<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

enum ChannelHistoryAction
{
    case Add;
    case Delete;
    case View;
    case Clear;
}
