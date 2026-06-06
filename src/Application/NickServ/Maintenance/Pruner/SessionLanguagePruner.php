<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\SessionLanguageRegistry;
use App\Application\Port\NetworkUserLookupPort;

final readonly class SessionLanguagePruner implements InMemoryPrunableInterface
{
    public function __construct(
        private readonly SessionLanguageRegistry $registry,
        private readonly NetworkUserLookupPort $userLookup,
    ) {}

    public function prune(): int
    {
        $validUids = $this->userLookup->listConnectedUids();

        return $this->registry->pruneSessionsNotIn($validUids);
    }
}
