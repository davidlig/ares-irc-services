<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Persistence\Doctrine;

use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Domain\Entity\Memo;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_key_exists;
use function ctype_digit;
use function is_array;
use function is_int;
use function is_string;

final readonly class MemoDoctrineRepository implements MemoRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(Memo $memo): void
    {
        $this->em->persist($memo);
        $this->em->flush();
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
        return $this->em
            ->getRepository(Memo::class)
            ->findBy(['targetNickId' => $nickId], ['createdAt' => 'ASC']);
    }

    /**
     * @return Memo[]
     */
    public function findByTargetChannel(int $channelId): array
    {
        return $this->em
            ->getRepository(Memo::class)
            ->findBy(['targetChannelId' => $channelId], ['createdAt' => 'ASC']);
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

    public function countUnreadByTargetNickIds(array $nickIds): array
    {
        if ([] === $nickIds) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('m.targetNickId AS nickId, COUNT(m.id) AS unreadCount')
            ->from(Memo::class, 'm')
            ->where('m.targetNickId IN (:nickIds)')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('nickIds', $nickIds)
            ->groupBy('m.targetNickId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('nickId', $row) || !array_key_exists('unreadCount', $row)) {
                throw new LogicException('Memo grouped unread count query returned an invalid row.');
            }
            $nickId = $row['nickId'];
            $unreadCount = $row['unreadCount'];
            if ((!is_int($nickId) && (!is_string($nickId) || !ctype_digit($nickId)))
                || (!is_int($unreadCount) && (!is_string($unreadCount) || !ctype_digit($unreadCount)))
            ) {
                throw new LogicException('Memo grouped unread count query returned an invalid row.');
            }
            $counts[(int) $nickId] = (int) $unreadCount;
        }

        return $counts;
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

    public function findByTargetNickAndIndex(int $nickId, int $index): ?Memo
    {
        if ($index < 1) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Memo::class, 'm')
            ->where('m.targetNickId = :nickId')
            ->setParameter('nickId', $nickId)
            ->orderBy('m.createdAt', 'ASC')
            ->setFirstResult($index - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $result || $result instanceof Memo) {
            return $result;
        }

        throw new LogicException('Memo index query returned an unexpected result.');
    }

    public function findByTargetChannelAndIndex(int $channelId, int $index): ?Memo
    {
        if ($index < 1) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Memo::class, 'm')
            ->where('m.targetChannelId = :channelId')
            ->setParameter('channelId', $channelId)
            ->orderBy('m.createdAt', 'ASC')
            ->setFirstResult($index - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $result || $result instanceof Memo) {
            return $result;
        }

        throw new LogicException('Memo index query returned an unexpected result.');
    }

    public function deleteByTargetNick(int $nickId): int
    {
        $affectedRows = $this->em->createQuery(
            'DELETE FROM App\\MemoServ\\Domain\\Entity\\Memo m WHERE m.targetNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();

        if (!is_int($affectedRows)) {
            throw new LogicException('Memo delete query did not return an affected row count.');
        }

        return $affectedRows;
    }

    public function deleteByTargetChannel(int $channelId): int
    {
        $affectedRows = $this->em->createQuery(
            'DELETE FROM App\\MemoServ\\Domain\\Entity\\Memo m WHERE m.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();

        if (!is_int($affectedRows)) {
            throw new LogicException('Memo delete query did not return an affected row count.');
        }

        return $affectedRows;
    }

    public function deleteAllForNick(int $nickId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\MemoServ\Domain\Entity\Memo m WHERE m.targetNickId = :nickId OR m.senderNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteAllForChannel(int $channelId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\MemoServ\Domain\Entity\Memo m WHERE m.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();
    }
}
