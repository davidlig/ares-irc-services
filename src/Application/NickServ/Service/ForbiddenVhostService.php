<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Domain\NickServ\Entity\ForbiddenVhost;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;

readonly class ForbiddenVhostService
{
    public function __construct(
        private ForbiddenVhostRepositoryInterface $repository,
    ) {
    }

    public function forbid(string $pattern, ?int $creatorNickId = null): ForbiddenVhost
    {
        $forbidden = ForbiddenVhost::create($pattern, $creatorNickId);
        $this->repository->save($forbidden);

        return $forbidden;
    }

    public function unforbid(string $pattern): bool
    {
        $forbidden = $this->repository->findByPattern($pattern);

        if (null === $forbidden) {
            return false;
        }

        $this->repository->remove($forbidden);

        return true;
    }

    public function matchesForbiddenPattern(string $vhost): bool
    {
        $forbiddenList = $this->repository->findAll();

        foreach ($forbiddenList as $forbidden) {
            if ($forbidden->matches($vhost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ForbiddenVhost[]
     */
    public function getAll(): array
    {
        return $this->repository->findAll();
    }

    public function count(): int
    {
        return $this->repository->countAll();
    }
}
