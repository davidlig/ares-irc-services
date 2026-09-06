<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class RegisteredNickDoctrineRepository implements RegisteredNickRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(RegisteredNick $nick): void
    {
        $this->em->persist($nick);
        $this->em->flush();
    }

    public function delete(RegisteredNick $nick): void
    {
        $this->em->remove($nick);
        $this->em->flush();
    }

    public function findByNick(string $nickname): ?RegisteredNick
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findOneBy(['nicknameLower' => strtolower($nickname)]);
    }

    public function findById(int $id): ?RegisteredNick
    {
        $nick = $this->em->find(RegisteredNick::class, $id);

        return $nick instanceof RegisteredNick ? $nick : null;
    }

    public function findByVhost(string $vhost): ?RegisteredNick
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findOneBy(['vhost' => $vhost]);
    }

    public function findByEmail(string $email): ?RegisteredNick
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('LOWER(n.email) = LOWER(:email)')
            ->andWhere('n.email IS NOT NULL')
            ->setParameter('email', $email)
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof RegisteredNick ? $result : null;
    }

    public function existsByNick(string $nickname): bool
    {
        return null !== $this->findByNick($nickname);
    }

    public function findRegisteredInactiveSince(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('COALESCE(n.lastSeenAt, n.registeredAt) < :threshold')
            ->andWhere('n.noExpire = false')
            ->setParameter('status', NickStatus::Registered)
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function deleteExpiredPending(): int
    {
        $result = $this->em
            ->createQuery(
                'DELETE FROM App\NickServ\Domain\Entity\RegisteredNick n
                 WHERE n.status = :status
                 AND n.expiresAt IS NOT NULL
                 AND n.expiresAt < :now'
            )
            ->setParameter('status', NickStatus::Pending)
            ->setParameter('now', new DateTimeImmutable()->format('Y-m-d H:i:s'))
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function findByStatus(NickStatus $status): array
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findBy(['status' => $status]);
    }

    public function findExpiredSuspensions(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.suspendedUntil IS NOT NULL')
            ->andWhere('n.suspendedUntil < :now')
            ->setParameter('status', NickStatus::Suspended)
            ->setParameter('now', new DateTimeImmutable()->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function findPendingDeletionBefore(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.pendingDeletionAt IS NOT NULL')
            ->andWhere('n.pendingDeletionAt <= :threshold')
            ->setParameter('status', NickStatus::PendingDeletion)
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function all(): array
    {
        return $this->em->getRepository(RegisteredNick::class)->findAll();
    }
}
