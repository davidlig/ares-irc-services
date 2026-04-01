<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function strtolower;

final readonly class GlineDoctrineRepository implements GlineRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Gline $gline): void
    {
        $this->em->persist($gline);
        $this->em->flush();
    }

    public function remove(Gline $gline): void
    {
        $this->em->remove($gline);
        $this->em->flush();
    }

    public function findById(int $id): ?Gline
    {
        return $this->em->find(Gline::class, $id);
    }

    public function findByMask(string $mask): ?Gline
    {
        return $this->em->getRepository(Gline::class)->findOneBy(['mask' => $mask]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(Gline::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function findByMaskPattern(string $pattern): array
    {
        $lowerPattern = strtolower($pattern);

        return $this->em
            ->createQuery(
                'SELECT g FROM App\Domain\OperServ\Entity\Gline g WHERE LOWER(g.mask) LIKE :pattern ORDER BY g.createdAt DESC'
            )
            ->setParameter('pattern', '%' . $lowerPattern . '%')
            ->getResult();
    }

    public function findExpired(): array
    {
        return $this->em
            ->createQuery(
                'SELECT g FROM App\Domain\OperServ\Entity\Gline g WHERE g.expiresAt IS NOT NULL AND g.expiresAt < :now ORDER BY g.createdAt DESC'
            )
            ->setParameter('now', new DateTimeImmutable())
            ->getResult();
    }

    public function findActive(): array
    {
        return $this->em
            ->createQuery(
                'SELECT g FROM App\Domain\OperServ\Entity\Gline g WHERE g.expiresAt IS NULL OR g.expiresAt > :now ORDER BY g.createdAt DESC'
            )
            ->setParameter('now', new DateTimeImmutable())
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->em
            ->createQuery('SELECT COUNT(g.id) FROM App\Domain\OperServ\Entity\Gline g')
            ->getSingleScalarResult();
    }

    public function clearCreatorNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE App\Domain\OperServ\Entity\Gline g SET g.creatorNickId = NULL WHERE g.creatorNickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
