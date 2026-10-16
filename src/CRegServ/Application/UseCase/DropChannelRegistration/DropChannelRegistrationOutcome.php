<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

enum DropChannelRegistrationOutcome
{
    case Dropped;
    case DroppedAndUnregistered;
    case InvalidChannel;
    case NoRequest;
}
