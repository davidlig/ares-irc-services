<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use App\Irc\Domain\Server\ServerLink;

interface IRCClientFactoryInterface
{
    public function create(string $protocolName, ServerLink $link): IRCClient;
}
