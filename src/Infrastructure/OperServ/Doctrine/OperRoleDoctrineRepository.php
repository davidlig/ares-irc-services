<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OperRoleDoctrineRepository implements OperRoleRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?OperRole
    {
        return $this->em->find(OperRole::class, $id);
    }

    public function findByName(string $name): ?OperRole
    {
        return $this->em->getRepository(OperRole::class)->findOneBy(['name' => strtoupper($name)]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(OperRole::class)->findBy([], ['name' => 'ASC']);
    }

    public function findProtected(): array
    {
        return $this->em->getRepository(OperRole::class)->findBy(['protected' => true], ['name' => 'ASC']);
    }

    public function save(OperRole $role): void
    {
        $this->em->persist($role);
        $this->em->flush();
    }

    public function remove(OperRole $role): void
    {
        $this->em->remove($role);
        $this->em->flush();
    }

    public function hasPermission(int $roleId, string $permissionName): bool
    {
        $role = $this->find($roleId);
        if (null === $role) {
            return false;
        }

        foreach ($role->getPermissions() as $permission) {
            if ($permission->getName() === $permissionName) {
                return true;
            }
        }

        return false;
    }
}
