<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

enum GlobalMessageType: string
{
    case Notice = 'NOTICE';
    case PrivateMessage = 'PRIVMSG';
}
