<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperAdmin;
use App\Domain\OperServ\Repository\OperAdminRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OperAdminDoctrineRepository implements OperAdminRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?OperAdmin
    {
        return $this->em->find(OperAdmin::class, $id);
    }

    public function findByNickId(int $nickId): ?OperAdmin
    {
        return $this->em->getRepository(OperAdmin::class)->findOneBy(['nickId' => $nickId]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(OperAdmin::class)->findBy([], ['addedAt' => 'DESC']);
    }

    public function findByRoleId(int $roleId): array
    {
        return $this->em->getRepository(OperAdmin::class)->findBy(['role' => $roleId], ['addedAt' => 'DESC']);
    }

    public function save(OperAdmin $admin): void
    {
        $this->em->persist($admin);
        $this->em->flush();
    }

    public function remove(OperAdmin $admin): void
    {
        $this->em->remove($admin);
        $this->em->flush();
    }

    public function countByRoleId(int $roleId): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(a.id) FROM App\Domain\OperServ\Entity\OperAdmin a WHERE a.role = :roleId'
            )
            ->setParameter('roleId', $roleId)
            ->getSingleScalarResult();
    }
}
