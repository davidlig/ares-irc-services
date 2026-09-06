<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use App\Domain\IRC\Server\ServerLink;

interface ConnectionFactoryInterface
{
    public function create(ServerLink $link): ConnectionInterface;
}
