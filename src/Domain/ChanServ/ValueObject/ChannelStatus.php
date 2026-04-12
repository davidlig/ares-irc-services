<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\ValueObject;

enum ChannelStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Forbidden = 'forbidden';
}
