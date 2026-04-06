<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final readonly class NickHistoryDoctrineRepository implements NickHistoryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function save(NickHistory $history): void
    {
        $this->em->persist($history);
        $this->em->flush();
    }

    public function findById(int $id): ?NickHistory
    {
        return $this->em->find(NickHistory::class, $id);
    }

    public function findByNickId(int $nickId, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('h')
            ->from(NickHistory::class, 'h')
            ->where('h.nickId = :nickId')
            ->setParameter('nickId', $nickId)
            ->orderBy('h.performedAt', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        $result = $qb->getQuery()->getResult();

        return array_filter($result, static fn ($row): bool => $row instanceof NickHistory);
    }

    public function countByNickId(int $nickId): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(h.id)')
            ->from(NickHistory::class, 'h')
            ->where('h.nickId = :nickId')
            ->setParameter('nickId', $nickId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteById(int $id): bool
    {
        $history = $this->findById($id);

        if (null === $history) {
            return false;
        }

        $this->em->remove($history);
        $this->em->flush();

        return true;
    }

    public function deleteByNickId(int $nickId): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(NickHistory::class, 'h')
            ->where('h.nickId = :nickId')
            ->setParameter('nickId', $nickId);

        return $qb->getQuery()->execute();
    }

    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(NickHistory::class, 'h')
            ->where('h.performedAt < :threshold')
            ->setParameter('threshold', $threshold);

        return $qb->getQuery()->execute();
    }
}
