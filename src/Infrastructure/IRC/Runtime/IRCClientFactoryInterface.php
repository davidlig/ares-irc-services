<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

use App\Domain\IRC\Server\ServerLink;

interface IRCClientFactoryInterface
{
    public function create(string $protocolName, ServerLink $link): IRCClient;
}
