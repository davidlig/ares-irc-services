<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use Doctrine\ORM\EntityManagerInterface;

class RegisteredNickDoctrineRepository implements RegisteredNickRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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

    public function existsByNick(string $nickname): bool
    {
        return $this->findByNick($nickname) !== null;
    }

    public function deleteExpiredPending(): int
    {
        return (int) $this->em
            ->createQuery(
                'DELETE FROM App\Domain\NickServ\Entity\RegisteredNick n
                 WHERE n.status = :status
                 AND n.expiresAt IS NOT NULL
                 AND n.expiresAt < :now'
            )
            ->setParameter('status', NickStatus::Pending)
            ->setParameter('now', new \DateTimeImmutable())
            ->execute();
    }

    public function findByStatus(NickStatus $status): array
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findBy(['status' => $status]);
    }

    public function all(): array
    {
        return $this->em->getRepository(RegisteredNick::class)->findAll();
    }
}
