<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;

final readonly class SessionLanguagePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private SessionLanguageRegistry $registry,
        private NetworkUserLookupPort $userLookup,
    ) {}

    public function prune(): int
    {
        $validUids = $this->userLookup->listConnectedUids();

        return $this->registry->pruneSessionsNotIn($validUids);
    }
}
