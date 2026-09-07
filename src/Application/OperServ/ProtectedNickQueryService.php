<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\OperServ\Port\In\ProtectedNickQuery;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;

final readonly class ProtectedNickQueryService implements ProtectedNickQuery
{
    public function __construct(
        private RootUserRegistry $rootUserRegistry,
        private OperIrcopRepositoryInterface $ircopRepository,
    ) {}

    public function isRootNickname(string $nickname): bool
    {
        return $this->rootUserRegistry->isRoot($nickname);
    }

    public function isIrcopNickId(int $nickId): bool
    {
        return null !== $this->ircopRepository->findByNickId($nickId);
    }

    public function resolveForcedVhost(int $nickId, string $nickname): ?string
    {
        $pattern = $this->ircopRepository->findByNickId($nickId)?->getRole()->getForcedVhostPattern();
        if (null === $pattern || '' === $pattern || !ForcedVhost::isValidPattern($pattern)) {
            return null;
        }

        return ForcedVhost::fromPattern($pattern)->generateVhost($nickname);
    }

    public function hasForcedVhost(int $nickId): bool
    {
        $pattern = $this->ircopRepository->findByNickId($nickId)?->getRole()->getForcedVhostPattern();

        return null !== $pattern && '' !== $pattern && ForcedVhost::isValidPattern($pattern);
    }
}
