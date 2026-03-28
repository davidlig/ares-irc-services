<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class ChannelAkickDoctrineRepository implements ChannelAkickRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(ChannelAkick $akick): void
    {
        $this->em->persist($akick);
        $this->em->flush();
    }

    public function remove(ChannelAkick $akick): void
    {
        $this->em->remove($akick);
        $this->em->flush();
    }

    public function findById(int $id): ?ChannelAkick
    {
        return $this->em->find(ChannelAkick::class, $id);
    }

    /**
     * @return ChannelAkick[]
     */
    public function listByChannel(int $channelId): array
    {
        $result = $this->em
            ->getRepository(ChannelAkick::class)
            ->findBy(['channelId' => $channelId], ['createdAt' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof ChannelAkick);
    }

    public function findByChannelAndMask(int $channelId, string $mask): ?ChannelAkick
    {
        return $this->em
            ->getRepository(ChannelAkick::class)
            ->findOneBy(['channelId' => $channelId, 'mask' => $mask]);
    }

    public function countByChannel(int $channelId): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(a.id) FROM App\Domain\ChanServ\Entity\ChannelAkick a WHERE a.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->getSingleScalarResult();
    }

    /**
     * @return ChannelAkick[]
     */
    public function findExpired(): array
    {
        return $this->em
            ->createQuery(
                'SELECT a FROM App\Domain\ChanServ\Entity\ChannelAkick a WHERE a.expiresAt IS NOT NULL AND a.expiresAt < :now'
            )
            ->setParameter('now', new DateTimeImmutable())
            ->getResult();
    }

    /**
     * @param int[] $channelIds
     *
     * @return ChannelAkick[]
     */
    public function findByChannelIds(array $channelIds): array
    {
        if ([] === $channelIds) {
            return [];
        }

        $result = $this->em
            ->getRepository(ChannelAkick::class)
            ->findBy(['channelId' => $channelIds]);

        return array_filter($result, static fn ($row): bool => $row instanceof ChannelAkick);
    }

    public function clearCreatorNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE App\Domain\ChanServ\Entity\ChannelAkick a SET a.creatorNickId = NULL WHERE a.creatorNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
