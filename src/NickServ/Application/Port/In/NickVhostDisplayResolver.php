<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/** Resolves the network-visible form of a stored NickServ vhost. */
interface NickVhostDisplayResolver
{
    public function getDisplayVhost(?string $storedVhost): string;
}
