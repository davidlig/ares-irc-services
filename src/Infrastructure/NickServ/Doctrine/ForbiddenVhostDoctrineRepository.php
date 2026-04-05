<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\ForbiddenVhost;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ForbiddenVhostDoctrineRepository implements ForbiddenVhostRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(ForbiddenVhost $forbiddenVhost): void
    {
        $this->em->persist($forbiddenVhost);
        $this->em->flush();
    }

    public function remove(ForbiddenVhost $forbiddenVhost): void
    {
        $this->em->remove($forbiddenVhost);
        $this->em->flush();
    }

    public function findById(int $id): ?ForbiddenVhost
    {
        return $this->em->find(ForbiddenVhost::class, $id);
    }

    public function findByPattern(string $pattern): ?ForbiddenVhost
    {
        return $this->em->getRepository(ForbiddenVhost::class)->findOneBy(['pattern' => $pattern]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(ForbiddenVhost::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function countAll(): int
    {
        return (int) $this->em
            ->createQuery('SELECT COUNT(f.id) FROM App\Domain\NickServ\Entity\ForbiddenVhost f')
            ->getSingleScalarResult();
    }

    public function clearCreatedByNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE App\Domain\NickServ\Entity\ForbiddenVhost f SET f.createdByNickId = NULL WHERE f.createdByNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
