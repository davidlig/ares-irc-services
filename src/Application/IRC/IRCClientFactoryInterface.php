<?php

declare(strict_types=1);

namespace App\Application\IRC;

use App\Domain\IRC\Server\ServerLink;

interface IRCClientFactoryInterface
{
    public function create(string $protocolName, ServerLink $link): IRCClient;
}
