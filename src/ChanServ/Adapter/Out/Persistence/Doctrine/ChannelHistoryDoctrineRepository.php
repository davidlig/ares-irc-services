<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelHistory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function is_numeric;

final readonly class ChannelHistoryDoctrineRepository implements ChannelHistoryRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(ChannelHistory $history): void
    {
        $this->em->persist($history);
        $this->em->flush();
    }

    public function findById(int $id): ?ChannelHistory
    {
        return $this->em->find(ChannelHistory::class, $id);
    }

    /**
     * @return ChannelHistory[]
     */
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

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<ChannelHistory> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof ChannelHistory));
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

        $result = $qb->getQuery()->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(ChannelHistory::class, 'h')
            ->where('h.performedAt < :threshold')
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        $result = $qb->getQuery()->execute();

        return is_numeric($result) ? (int) $result : 0;
    }
}
