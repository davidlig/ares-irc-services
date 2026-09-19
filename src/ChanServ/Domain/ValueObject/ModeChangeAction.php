<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum ModeChangeAction: string
{
    case Add = 'add';
    case Remove = 'remove';
}
