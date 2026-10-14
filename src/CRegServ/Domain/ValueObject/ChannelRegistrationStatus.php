<?php

declare(strict_types=1);

namespace App\CRegServ\Domain\ValueObject;

enum ChannelRegistrationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
}
