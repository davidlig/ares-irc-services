<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OperIrcopDoctrineRepository implements OperIrcopRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?OperIrcop
    {
        return $this->em->find(OperIrcop::class, $id);
    }

    public function findByNickId(int $nickId): ?OperIrcop
    {
        return $this->em->getRepository(OperIrcop::class)->findOneBy(['nickId' => $nickId]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(OperIrcop::class)->findBy([], ['addedAt' => 'DESC']);
    }

    public function findByRoleId(int $roleId): array
    {
        return $this->em->getRepository(OperIrcop::class)->findBy(['role' => $roleId], ['addedAt' => 'DESC']);
    }

    public function save(OperIrcop $ircop): void
    {
        $this->em->persist($ircop);
        $this->em->flush();
    }

    public function remove(OperIrcop $ircop): void
    {
        $this->em->remove($ircop);
        $this->em->flush();
    }

    public function countByRoleId(int $roleId): int
    {
        return (int) $this->em
            ->createQuery(
                'SELECT COUNT(i.id) FROM App\Domain\OperServ\Entity\OperIrcop i WHERE i.role = :roleId'
            )
            ->setParameter('roleId', $roleId)
            ->getSingleScalarResult();
    }
}
