<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

enum BadmailAction
{
    case Add;
    case Delete;
    case Clear;
    case List;
}
