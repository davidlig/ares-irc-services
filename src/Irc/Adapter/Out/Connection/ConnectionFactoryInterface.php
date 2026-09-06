<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use App\Irc\Domain\Server\ServerLink;

interface ConnectionFactoryInterface
{
    public function create(ServerLink $link): ConnectionInterface;
}
