<?php

declare(strict_types=1);

namespace App\Domain\IRC\Connection;

enum ConnectionStatus
{
    case Disconnected;
    case Connecting;
    case Connected;
    case Authenticating;
    case Authenticated;
    case Error;
}
