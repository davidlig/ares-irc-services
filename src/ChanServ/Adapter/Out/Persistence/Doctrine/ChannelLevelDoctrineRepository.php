<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelLevel;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final readonly class ChannelLevelDoctrineRepository implements ChannelLevelRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

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

        // @phpstan-ignore instanceof.alwaysTrue
        return array_filter($result, static fn ($row): bool => $row instanceof ChannelLevel);
    }

    public function removeAllForChannel(int $channelId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM ' . ChannelLevel::class . ' l WHERE l.channelId = :cid'
            )
            ->setParameter('cid', $channelId)
            ->execute();
    }
}
