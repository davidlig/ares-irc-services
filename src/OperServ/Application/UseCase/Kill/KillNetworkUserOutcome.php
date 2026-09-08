<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Kill;

enum KillNetworkUserOutcome: string
{
    case Killed = 'killed';
    case NotOnline = 'not_online';
    case ProtectedRoot = 'protected_root';
    case ProtectedIrcOperator = 'protected_irc_operator';
    case NetworkUnavailable = 'network_unavailable';
}
