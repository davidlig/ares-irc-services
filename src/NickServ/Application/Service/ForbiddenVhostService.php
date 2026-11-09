<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Domain\Entity\ForbiddenVhost;
use DateTimeImmutable;

use function array_any;
use function array_filter;
use function array_values;

final class ForbiddenVhostService
{
    /** @var ForbiddenVhost[]|null */
    private ?array $forbiddenVhosts = null;

    public function __construct(
        private ForbiddenVhostRepositoryInterface $repository,
    ) {}

    public function forbid(string $pattern, ?int $creatorNickId, DateTimeImmutable $createdAt): ForbiddenVhost
    {
        $forbidden = ForbiddenVhost::create($pattern, $creatorNickId, $createdAt);
        $this->repository->save($forbidden);

        if (null !== $this->forbiddenVhosts) {
            $this->forbiddenVhosts[] = $forbidden;
        }

        return $forbidden;
    }

    public function unforbid(string $pattern): bool
    {
        $forbidden = $this->repository->findByPattern($pattern);

        if (null === $forbidden) {
            return false;
        }

        $this->repository->remove($forbidden);

        if (null !== $this->forbiddenVhosts) {
            $this->forbiddenVhosts = array_values(array_filter(
                $this->forbiddenVhosts,
                static fn (ForbiddenVhost $cached): bool => $cached->getPattern() !== $pattern,
            ));
        }

        return true;
    }

    public function matchesForbiddenPattern(string $vhost): bool
    {
        return array_any($this->forbiddenVhosts(), static fn (ForbiddenVhost $forbidden): bool => $forbidden->matches($vhost));
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

    /** @return ForbiddenVhost[] */
    private function forbiddenVhosts(): array
    {
        return $this->forbiddenVhosts ??= $this->repository->findAll();
    }
}
