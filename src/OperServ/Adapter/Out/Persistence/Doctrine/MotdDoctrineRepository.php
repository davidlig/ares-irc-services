<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Domain\Entity\Motd;
use App\OperServ\Domain\Repository\MotdRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MotdDoctrineRepository implements MotdRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

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
        /** @var array<mixed> $result */
        $result = $this->em
            ->createQuery(
                'SELECT m FROM App\OperServ\Domain\Entity\Motd m
                 WHERE m.enabled = true
                 AND (m.expiresAt IS NULL OR m.expiresAt > CURRENT_TIMESTAMP())
                 ORDER BY m.createdAt ASC'
            )
            ->getResult();

        /* @var array<Motd> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof Motd));
    }

    public function countActive(): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(m.id) FROM App\OperServ\Domain\Entity\Motd m
                 WHERE m.enabled = true
                 AND (m.expiresAt IS NULL OR m.expiresAt > CURRENT_TIMESTAMP())'
            )
            ->getSingleScalarResult();
    }

    public function findExpired(): array
    {
        /** @var array<mixed> $result */
        $result = $this->em
            ->createQuery(
                'SELECT m FROM App\OperServ\Domain\Entity\Motd m
                 WHERE m.expiresAt IS NOT NULL
                 AND m.expiresAt <= CURRENT_TIMESTAMP()
                 ORDER BY m.createdAt ASC'
            )
            ->getResult();

        /* @var array<Motd> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof Motd));
    }

    public function deleteByNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM App\OperServ\Domain\Entity\Motd m WHERE m.creatorNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
