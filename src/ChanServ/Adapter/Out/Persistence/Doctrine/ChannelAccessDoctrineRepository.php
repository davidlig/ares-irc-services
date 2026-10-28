<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function is_numeric;

final readonly class ChannelAccessDoctrineRepository implements ChannelAccessRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(ChannelAccess $access): void
    {
        $this->em->persist($access);
        $this->em->flush();
    }

    public function remove(ChannelAccess $access): void
    {
        $this->em->remove($access);
        $this->em->flush();
    }

    public function findByChannelAndNick(int $channelId, int $nickId): ?ChannelAccess
    {
        return $this->em
            ->getRepository(ChannelAccess::class)
            ->findOneBy(['channelId' => $channelId, 'nickId' => $nickId]);
    }

    /**
     * @return ChannelAccess[]
     */
    public function listByChannel(int $channelId): array
    {
        $result = $this->em
            ->getRepository(ChannelAccess::class)
            ->findBy(['channelId' => $channelId], ['level' => 'DESC']);

        // @phpstan-ignore instanceof.alwaysTrue
        return array_filter($result, static fn ($row): bool => $row instanceof ChannelAccess);
    }

    public function countByChannel(int $channelId): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(a.id) FROM ' . ChannelAccess::class . ' a WHERE a.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->getSingleScalarResult();
    }

    /**
     * @return ChannelAccess[]
     */
    public function findByNick(int $nickId): array
    {
        return $this->em
            ->getRepository(ChannelAccess::class)
            ->findBy(['nickId' => $nickId], ['level' => 'DESC']);
    }

    public function findEstablishedByNick(int $nickId): array
    {
        /** @var array<mixed> $entries */
        $entries = $this->em->createQueryBuilder()
            ->select('a')
            ->from(ChannelAccess::class, 'a')
            ->leftJoin(RegisteredChannel::class, 'c', 'WITH', 'c.id = a.channelId')
            ->where('a.nickId = :nickId')
            ->andWhere('(c.id IS NULL OR c.status NOT IN (:requestStatuses))')
            ->setParameter('nickId', $nickId)
            ->setParameter('requestStatuses', [ChannelStatus::Pending->value, ChannelStatus::Rejected->value])
            ->orderBy('a.level', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($entries, static fn (mixed $entry): bool => $entry instanceof ChannelAccess));
    }

    public function deleteByNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM ' . ChannelAccess::class . ' a WHERE a.nickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteByChannelId(int $channelId): int
    {
        $result = $this->em
            ->createQuery(
                'DELETE FROM ' . ChannelAccess::class . ' a WHERE a.channelId = :channelId'
            )
            ->setParameter('channelId', $channelId)
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }
}
