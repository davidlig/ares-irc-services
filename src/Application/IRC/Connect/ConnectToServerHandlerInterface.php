<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

use App\Application\IRC\IRCClient;

interface ConnectToServerHandlerInterface
{
    public function handle(ConnectToServerCommand $command): IRCClient;
}
