<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\BurstChannelActivity;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BurstChannelActivityWriter implements BurstChannelActivity
{
    public function __construct(private EntityManagerInterface $em) {}

    public function touchByIds(array $channelIds, DateTimeImmutable $at): void
    {
        if ([] === $channelIds) {
            return;
        }
        $this->em->createQuery('UPDATE ' . RegisteredChannel::class . ' c SET c.lastUsedAt = :at WHERE c.id IN (:ids)')
            ->setParameter('at', $at)
            ->setParameter('ids', $channelIds)
            ->execute();
    }
}
