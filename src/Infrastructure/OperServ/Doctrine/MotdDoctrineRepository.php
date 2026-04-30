<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MotdDoctrineRepository implements MotdRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Motd $motd): void
    {
        $this->em->persist($motd);
        $this->em->flush();
    }

    public function remove(Motd $motd): void
    {
        $this->em->remove($motd);
        $this->em->flush();
    }

    public function findById(int $id): ?Motd
    {
        return $this->em->find(Motd::class, $id);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(Motd::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function findActive(): array
    {
        return $this->em
            ->createQuery(
                'SELECT m FROM App\Domain\OperServ\Entity\Motd m
                 WHERE m.enabled = true
                 AND (m.expiresAt IS NULL OR m.expiresAt > CURRENT_TIMESTAMP())
                 ORDER BY m.createdAt ASC'
            )
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(m.id) FROM App\Domain\OperServ\Entity\Motd m
                 WHERE m.enabled = true
                 AND (m.expiresAt IS NULL OR m.expiresAt > CURRENT_TIMESTAMP())'
            )
            ->getSingleScalarResult();
    }

    public function findExpired(): array
    {
        return $this->em
            ->createQuery(
                'SELECT m FROM App\Domain\OperServ\Entity\Motd m
                 WHERE m.expiresAt IS NOT NULL
                 AND m.expiresAt <= CURRENT_TIMESTAMP()
                 ORDER BY m.createdAt ASC'
            )
            ->getResult();
    }

    public function deleteByNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM App\Domain\OperServ\Entity\Motd m WHERE m.creatorNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
