<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

enum DropChannelRegistrationOutcome
{
    case Dropped;
    case InvalidChannel;
    case NoRequest;
}
