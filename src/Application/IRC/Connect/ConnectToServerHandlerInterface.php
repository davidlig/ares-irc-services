<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

use App\Application\IRC\IrcSessionInterface;

interface ConnectToServerHandlerInterface
{
    public function handle(ConnectToServerCommand $command): IrcSessionInterface;
}
