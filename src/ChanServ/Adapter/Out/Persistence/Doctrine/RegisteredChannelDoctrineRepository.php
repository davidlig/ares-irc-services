<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function strtolower;

final readonly class RegisteredChannelDoctrineRepository implements RegisteredChannelRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

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

        // @phpstan-ignore instanceof.alwaysTrue
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

        // @phpstan-ignore instanceof.alwaysTrue
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

        // @phpstan-ignore instanceof.alwaysTrue
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
            ->andWhere('c.noExpire = false')
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredChannel> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel));
    }

    public function clearSuccessorNickId(int $successorNickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE ' . RegisteredChannel::class . ' c SET c.successorNickId = NULL WHERE c.successorNickId = :successorNickId'
            )
            ->setParameter('successorNickId', $successorNickId)
            ->execute();
    }

    public function findExpiredSuspensions(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(RegisteredChannel::class, 'c')
            ->where('c.status = :status')
            ->andWhere('c.suspendedUntil IS NOT NULL')
            ->andWhere('c.suspendedUntil <= :now')
            ->setParameter('status', ChannelStatus::Suspended)
            ->setParameter('now', new DateTimeImmutable()->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredChannel> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel));
    }

    public function findPendingDeletionBefore(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(RegisteredChannel::class, 'c')
            ->where('c.status = :status')
            ->andWhere('c.pendingDeletionAt IS NOT NULL')
            ->andWhere('c.pendingDeletionAt <= :threshold')
            ->setParameter('status', ChannelStatus::PendingDeletion)
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredChannel> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel));
    }

    public function findForbiddenChannels(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(RegisteredChannel::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', ChannelStatus::Forbidden);

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredChannel> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel));
    }
}
