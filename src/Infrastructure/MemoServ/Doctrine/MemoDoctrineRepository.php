<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function count;

final class MemoDoctrineRepository implements MemoRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Memo $memo): void
    {
        $this->em->persist($memo);
        $this->em->flush($memo);
    }

    public function delete(Memo $memo): void
    {
        $this->em->remove($memo);
        $this->em->flush();
    }

    /**
     * @return Memo[]
     */
    public function findByTargetNick(int $nickId): array
    {
        $result = $this->em
            ->getRepository(Memo::class)
            ->findBy(['targetNickId' => $nickId], ['createdAt' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof Memo);
    }

    /**
     * @return Memo[]
     */
    public function findByTargetChannel(int $channelId): array
    {
        $result = $this->em
            ->getRepository(Memo::class)
            ->findBy(['targetChannelId' => $channelId], ['createdAt' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof Memo);
    }

    public function countUnreadByTargetNick(int $nickId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memo::class, 'm')
            ->where('m.targetNickId = :nickId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('nickId', $nickId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadByTargetChannel(int $channelId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memo::class, 'm')
            ->where('m.targetChannelId = :channelId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('channelId', $channelId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTargetNick(int $nickId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memo::class, 'm')
            ->where('m.targetNickId = :nickId')
            ->setParameter('nickId', $nickId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTargetChannel(int $channelId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Memo::class, 'm')
            ->where('m.targetChannelId = :channelId')
            ->setParameter('channelId', $channelId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findById(int $id): ?Memo
    {
        $memo = $this->em->find(Memo::class, $id);

        return $memo instanceof Memo ? $memo : null;
    }

    public function findByTargetNickAndIndex(int $nickId, int $index): ?Memo
    {
        $list = $this->findByTargetNick($nickId);
        $oneBased = $index - 1;
        if ($oneBased < 0 || $oneBased >= count($list)) {
            return null;
        }

        $memo = $list[$oneBased];

        return $memo instanceof Memo ? $memo : null;
    }

    public function findByTargetChannelAndIndex(int $channelId, int $index): ?Memo
    {
        $list = $this->findByTargetChannel($channelId);
        $oneBased = $index - 1;
        if ($oneBased < 0 || $oneBased >= count($list)) {
            return null;
        }

        $memo = $list[$oneBased];

        return $memo instanceof Memo ? $memo : null;
    }

    public function deleteAllForNick(int $nickId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Domain\MemoServ\Entity\Memo m WHERE m.targetNickId = :nickId OR m.senderNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteAllForChannel(int $channelId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Domain\MemoServ\Entity\Memo m WHERE m.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();
    }
}
