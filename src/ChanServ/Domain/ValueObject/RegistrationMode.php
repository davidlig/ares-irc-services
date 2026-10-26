<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum RegistrationMode: string
{
    case Direct = 'DIRECT';
    case Approval = 'APPROVAL';
}
