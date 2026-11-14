<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_filter;
use function array_values;
use function is_numeric;

final readonly class ChannelAkickDoctrineRepository implements ChannelAkickRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(ChannelAkick $akick): void
    {
        $this->em->persist($akick);
        $this->em->flush();
    }

    public function saveAll(array $akicks): void
    {
        foreach ($akicks as $akick) {
            $this->em->persist($akick);
        }
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

    public function deleteByChannelId(int $channelId): int
    {
        $result = $this->em
            ->createQuery(
                'DELETE FROM ' . ChannelAkick::class . ' a WHERE a.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return 0;
        }

        $result = $this->em
            ->createQuery(
                'DELETE FROM ' . ChannelAkick::class . ' a WHERE a.id IN (:ids)'
            )
            ->setParameter('ids', $ids)
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Expired entries ordered by expiry then id, strictly after the $after keyset cursor.
     *
     * @return ChannelAkick[]
     */
    public function findExpired(?ChannelAkick $after = null, ?int $limit = null): array
    {
        $dql = 'SELECT a FROM ' . ChannelAkick::class . ' a WHERE a.expiresAt IS NOT NULL AND a.expiresAt < :now';
        $parameters = ['now' => new DateTimeImmutable()->format('Y-m-d H:i:s')];
        $afterExpiresAt = $after?->getExpiresAt();
        if (null !== $after) {
            if (null === $afterExpiresAt) {
                throw new LogicException('AKICK expiry cursor must be an expired entry.');
            }
            $dql .= ' AND a.expiresAt >= :afterExpiresAt AND (a.expiresAt > :afterExpiresAt OR a.id > :afterId)';
            $parameters['afterExpiresAt'] = $afterExpiresAt->format('Y-m-d H:i:s');
            $parameters['afterId'] = $after->getId();
        }

        $query = $this->em->createQuery($dql . ' ORDER BY a.expiresAt ASC, a.id ASC');
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        /** @var ChannelAkick[] $result */
        $result = $query->getResult();

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
