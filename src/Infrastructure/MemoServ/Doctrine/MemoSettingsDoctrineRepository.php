<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\MemoSettings;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class MemoSettingsDoctrineRepository implements MemoSettingsRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(MemoSettings $settings): void
    {
        $this->em->persist($settings);
        $this->em->flush();
    }

    public function delete(MemoSettings $settings): void
    {
        $this->em->remove($settings);
        $this->em->flush();
    }

    public function findByTargetNick(int $nickId): ?MemoSettings
    {
        return $this->em
            ->getRepository(MemoSettings::class)
            ->findOneBy(['targetNickId' => $nickId, 'targetChannelId' => null]);
    }

    public function findByTargetChannel(int $channelId): ?MemoSettings
    {
        return $this->em
            ->getRepository(MemoSettings::class)
            ->findOneBy(['targetNickId' => null, 'targetChannelId' => $channelId]);
    }

    public function isEnabledForNick(int $nickId): bool
    {
        $settings = $this->findByTargetNick($nickId);

        return null === $settings || $settings->isEnabled();
    }

    public function isEnabledForChannel(int $channelId): bool
    {
        $settings = $this->findByTargetChannel($channelId);

        return null === $settings || $settings->isEnabled();
    }

    public function deleteAllForNick(int $nickId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Domain\MemoServ\Entity\MemoSettings s WHERE s.targetNickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function deleteAllForChannel(int $channelId): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Domain\MemoServ\Entity\MemoSettings s WHERE s.targetChannelId = :channelId'
        )
            ->setParameter('channelId', $channelId)
            ->execute();
    }
}
