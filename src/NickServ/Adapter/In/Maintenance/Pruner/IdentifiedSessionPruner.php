<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;

final readonly class IdentifiedSessionPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private IdentifiedSessionRegistry $registry,
        private NetworkUserLookupPort $userLookup,
    ) {}

    public function prune(): int
    {
        return $this->registry->pruneDisconnected($this->userLookup->isConnectedUid(...));
    }
}
