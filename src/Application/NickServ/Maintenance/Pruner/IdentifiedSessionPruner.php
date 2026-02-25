<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance\Pruner;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;

final readonly class IdentifiedSessionPruner implements InMemoryPrunableInterface
{
    public function __construct(
        private readonly IdentifiedSessionRegistry $registry,
        private readonly NetworkUserRepositoryInterface $userRepository,
    ) {
    }

    public function prune(): int
    {
        $users = $this->userRepository->all();
        $validUids = array_map(static fn ($u) => $u->uid->value, $users);

        return $this->registry->pruneSessionsNotIn($validUids);
    }
}
