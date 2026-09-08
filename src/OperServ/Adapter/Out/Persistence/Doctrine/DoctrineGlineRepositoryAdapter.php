<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Domain\Entity\Gline;
use App\OperServ\Domain\Repository\GlineRepositoryInterface;
use DateTimeImmutable;

final readonly class DoctrineGlineRepositoryAdapter implements GlineRepository
{
    public function __construct(private GlineRepositoryInterface $repository) {}

    public function findByMask(string $mask): ?GlineEntry
    {
        $gline = $this->repository->findByMask($mask);

        return null === $gline ? null : $this->entry($gline);
    }

    public function findAll(): array
    {
        return array_values(array_map($this->entry(...), $this->repository->findAll()));
    }

    public function findByMaskPattern(string $pattern): array
    {
        return array_values(array_map($this->entry(...), $this->repository->findByMaskPattern($pattern)));
    }

    public function countAll(): int
    {
        return $this->repository->countAll();
    }

    public function save(string $mask, ?int $creatorAccountId, string $reason, ?DateTimeImmutable $expiresAt): void
    {
        $this->repository->save(Gline::create($mask, $creatorAccountId, $reason, $expiresAt));
    }

    public function remove(GlineEntry $entry): void
    {
        $legacy = $this->repository->findByMask($entry->mask);
        if (null !== $legacy) {
            $this->repository->remove($legacy);
        }
    }

    private function entry(Gline $gline): GlineEntry
    {
        return new GlineEntry(
            $gline->getMask(),
            $gline->getCreatorNickId(),
            $gline->getReason(),
            $gline->getCreatedAt(),
            $gline->getExpiresAt(),
        );
    }
}
