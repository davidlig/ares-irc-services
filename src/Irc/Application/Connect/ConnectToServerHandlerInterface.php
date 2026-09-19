<?php

declare(strict_types=1);

namespace App\Irc\Application\Connect;

use App\Irc\Application\IrcSessionInterface;

interface ConnectToServerHandlerInterface
{
    public function handle(ConnectToServerCommand $command): IrcSessionInterface;
}
