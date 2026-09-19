<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

enum ManageChannelLevelsAction
{
    case Unknown;
    case List;
    case Set;
    case Reset;
}
