<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class ChannelAccessDoctrineRepository implements ChannelAccessRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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

        return array_filter($result, static fn ($row): bool => $row instanceof ChannelAccess);
    }

    public function countByChannel(int $channelId): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(a.id) FROM App\Domain\ChanServ\Entity\ChannelAccess a WHERE a.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->getSingleScalarResult();
    }
}
