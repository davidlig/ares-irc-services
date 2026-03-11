<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\MemoIgnore;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final class MemoIgnoreDoctrineRepository implements MemoIgnoreRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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
        $result = $this->em
            ->getRepository(MemoIgnore::class)
            ->findBy(
                ['targetNickId' => $targetNickId, 'targetChannelId' => null],
                ['ignoredNickId' => 'ASC']
            );

        return array_filter($result, static fn ($row): bool => $row instanceof MemoIgnore);
    }

    /**
     * @return MemoIgnore[]
     */
    public function listByTargetChannel(int $targetChannelId): array
    {
        $result = $this->em
            ->getRepository(MemoIgnore::class)
            ->findBy(
                ['targetNickId' => null, 'targetChannelId' => $targetChannelId],
                ['ignoredNickId' => 'ASC']
            );

        return array_filter($result, static fn ($row): bool => $row instanceof MemoIgnore);
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
            'DELETE FROM App\Domain\MemoServ\Entity\MemoIgnore i WHERE i.targetNickId = :nickId OR i.ignoredNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteAllForChannel(int $channelId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Domain\MemoServ\Entity\MemoIgnore i WHERE i.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();
    }
}
