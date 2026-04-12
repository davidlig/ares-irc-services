<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelHistory;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final readonly class ChannelHistoryDoctrineRepository implements ChannelHistoryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function save(ChannelHistory $history): void
    {
        $this->em->persist($history);
        $this->em->flush();
    }

    public function findById(int $id): ?ChannelHistory
    {
        return $this->em->find(ChannelHistory::class, $id);
    }

    public function findByChannelId(int $channelId, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('h')
            ->from(ChannelHistory::class, 'h')
            ->where('h.channelId = :channelId')
            ->setParameter('channelId', $channelId)
            ->orderBy('h.performedAt', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        $result = $qb->getQuery()->getResult();

        return array_filter($result, static fn ($row): bool => $row instanceof ChannelHistory);
    }

    public function countByChannelId(int $channelId): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(h.id)')
            ->from(ChannelHistory::class, 'h')
            ->where('h.channelId = :channelId')
            ->setParameter('channelId', $channelId);

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

    public function deleteByChannelId(int $channelId): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(ChannelHistory::class, 'h')
            ->where('h.channelId = :channelId')
            ->setParameter('channelId', $channelId);

        return $qb->getQuery()->execute();
    }

    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(ChannelHistory::class, 'h')
            ->where('h.performedAt < :threshold')
            ->setParameter('threshold', $threshold);

        return $qb->getQuery()->execute();
    }
}
