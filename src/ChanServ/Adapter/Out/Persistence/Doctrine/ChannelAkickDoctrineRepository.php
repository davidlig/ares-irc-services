<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final readonly class ChannelAkickDoctrineRepository implements ChannelAkickRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

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

        // @phpstan-ignore instanceof.alwaysTrue
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
                'SELECT COUNT(a.id) FROM ' . ChannelAkick::class . ' a WHERE a.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->getSingleScalarResult();
    }

    /**
     * @return ChannelAkick[]
     */
    public function findExpired(): array
    {
        /** @var ChannelAkick[] $result */
        $result = $this->em
            ->createQuery(
                'SELECT a FROM ' . ChannelAkick::class . ' a WHERE a.expiresAt IS NOT NULL AND a.expiresAt < :now'
            )
            ->setParameter('now', new DateTimeImmutable()->format('Y-m-d H:i:s'))
            ->getResult();

        return $result;
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

        // @phpstan-ignore instanceof.alwaysTrue
        return array_filter($result, static fn ($row): bool => $row instanceof ChannelAkick);
    }

    public function clearCreatorNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE ' . ChannelAkick::class . ' a SET a.creatorNickId = NULL WHERE a.creatorNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
