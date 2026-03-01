<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class ChannelLevelDoctrineRepository implements ChannelLevelRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(ChannelLevel $level): void
    {
        $this->em->persist($level);
        $this->em->flush();
    }

    public function findByChannelAndKey(int $channelId, string $levelKey): ?ChannelLevel
    {
        return $this->em
            ->getRepository(ChannelLevel::class)
            ->findOneBy(['channelId' => $channelId, 'levelKey' => $levelKey]);
    }

    /**
     * @return ChannelLevel[]
     */
    public function listByChannel(int $channelId): array
    {
        $result = $this->em
            ->getRepository(ChannelLevel::class)
            ->findBy(['channelId' => $channelId], ['levelKey' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof ChannelLevel);
    }

    public function removeAllForChannel(int $channelId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM App\Domain\ChanServ\Entity\ChannelLevel l WHERE l.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->execute();
    }
}
