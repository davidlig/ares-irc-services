<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\SessionLanguageRegistry;
use App\Irc\Application\Port\In\NetworkUserLookupPort;

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
