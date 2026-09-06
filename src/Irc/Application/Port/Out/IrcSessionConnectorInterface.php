<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\Out;

use App\Irc\Application\IrcSessionInterface;
use App\Irc\Domain\Server\ServerLink;

interface IrcSessionConnectorInterface
{
    public function connect(string $protocolName, ServerLink $serverLink): IrcSessionInterface;
}
