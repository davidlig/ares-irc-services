<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class RegisteredChannelDoctrineRepository implements RegisteredChannelRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(RegisteredChannel $channel): void
    {
        $this->em->persist($channel);
        $this->em->flush();
    }

    public function delete(RegisteredChannel $channel): void
    {
        $this->em->remove($channel);
        $this->em->flush();
    }

    public function findByChannelName(string $channelName): ?RegisteredChannel
    {
        $nameLower = strtolower($channelName);

        return $this->em
            ->getRepository(RegisteredChannel::class)
            ->findOneBy(['nameLower' => $nameLower]);
    }

    public function existsByChannelName(string $channelName): bool
    {
        return null !== $this->findByChannelName($channelName);
    }

    /**
     * @return RegisteredChannel[]
     */
    public function findByFounderNickId(int $founderNickId): array
    {
        $result = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['founderNickId' => $founderNickId], ['name' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    /**
     * @return RegisteredChannel[]
     */
    public function findBySuccessorNickId(int $successorNickId): array
    {
        $result = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['successorNickId' => $successorNickId], ['name' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    /**
     * @return RegisteredChannel[]
     */
    public function listAll(): array
    {
        $result = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy([], ['name' => 'ASC']);

        return array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['id' => $ids]);
    }

    public function findRegisteredInactiveSince(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(RegisteredChannel::class, 'c')
            ->where('COALESCE(c.lastUsedAt, c.createdAt) < :threshold')
            ->setParameter('threshold', $threshold);

        return array_filter($qb->getQuery()->getResult(), static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    public function clearSuccessorNickId(int $successorNickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE App\Domain\ChanServ\Entity\RegisteredChannel c SET c.successorNickId = NULL WHERE c.successorNickId = :successorNickId'
            )
            ->setParameter('successorNickId', $successorNickId)
            ->execute();
    }
}
