<?php

declare(strict_types=1);

namespace App\NickServ\Domain\ValueObject;

enum RegistrationMode: string
{
    case Email = 'EMAIL';
    case Token = 'TOKEN';
}
