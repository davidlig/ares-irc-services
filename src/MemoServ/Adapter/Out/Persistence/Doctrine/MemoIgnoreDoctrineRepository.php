<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Persistence\Doctrine;

use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Domain\Entity\MemoIgnore;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MemoIgnoreDoctrineRepository implements MemoIgnoreRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(MemoIgnore $ignore): void
    {
        $this->em->persist($ignore);
        $this->em->flush();
    }

    public function delete(MemoIgnore $ignore): void
    {
        $this->em->remove($ignore);
        $this->em->flush();
    }

    public function findByTargetNickAndIgnored(int $targetNickId, int $ignoredNickId): ?MemoIgnore
    {
        return $this->em
            ->getRepository(MemoIgnore::class)
            ->findOneBy([
                'targetNickId' => $targetNickId,
                'targetChannelId' => null,
                'ignoredNickId' => $ignoredNickId,
            ]);
    }

    public function findByTargetChannelAndIgnored(int $targetChannelId, int $ignoredNickId): ?MemoIgnore
    {
        return $this->em
            ->getRepository(MemoIgnore::class)
            ->findOneBy([
                'targetNickId' => null,
                'targetChannelId' => $targetChannelId,
                'ignoredNickId' => $ignoredNickId,
            ]);
    }

    /**
     * @return MemoIgnore[]
     */
    public function listByTargetNick(int $targetNickId): array
    {
        return $this->em
            ->getRepository(MemoIgnore::class)
            ->findBy(
                ['targetNickId' => $targetNickId, 'targetChannelId' => null],
                ['ignoredNickId' => 'ASC']
            );
    }

    /**
     * @return MemoIgnore[]
     */
    public function listByTargetChannel(int $targetChannelId): array
    {
        return $this->em
            ->getRepository(MemoIgnore::class)
            ->findBy(
                ['targetNickId' => null, 'targetChannelId' => $targetChannelId],
                ['ignoredNickId' => 'ASC']
            );
    }

    public function countByTargetNick(int $targetNickId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(MemoIgnore::class, 'i')
            ->where('i.targetNickId = :nickId')
            ->andWhere('i.targetChannelId IS NULL')
            ->setParameter('nickId', $targetNickId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTargetChannel(int $targetChannelId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(MemoIgnore::class, 'i')
            ->where('i.targetChannelId = :channelId')
            ->setParameter('channelId', $targetChannelId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteAllForNick(int $nickId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\MemoServ\Domain\Entity\MemoIgnore i WHERE i.targetNickId = :nickId OR i.ignoredNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteAllForChannel(int $channelId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\MemoServ\Domain\Entity\MemoIgnore i WHERE i.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();
    }
}
