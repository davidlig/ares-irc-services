<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OperPermissionDoctrineRepository implements OperPermissionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?OperPermission
    {
        return $this->em->find(OperPermission::class, $id);
    }

    public function findByName(string $name): ?OperPermission
    {
        return $this->em->getRepository(OperPermission::class)->findOneBy(['name' => $name]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(OperPermission::class)->findBy([], ['name' => 'ASC']);
    }

    public function save(OperPermission $permission): void
    {
        $this->em->persist($permission);
        $this->em->flush();
    }
}
