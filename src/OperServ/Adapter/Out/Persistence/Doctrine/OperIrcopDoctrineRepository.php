<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

final readonly class OperIrcopDoctrineRepository implements OperIrcopRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function find(int $id): ?OperIrcop
    {
        return $this->em->find(OperIrcop::class, $id);
    }

    public function findByNickId(int $nickId): ?OperIrcop
    {
        return $this->em->getRepository(OperIrcop::class)->findOneBy(['nickId' => $nickId]);
    }

    public function findByNickIdWithRolePermissions(int $nickId): ?OperIrcop
    {
        $result = $this->em->createQuery(
            'SELECT i, r, p FROM App\\OperServ\\Domain\\Entity\\OperIrcop i JOIN i.role r LEFT JOIN r.permissions p WHERE i.nickId = :nickId'
        )
            ->setParameter('nickId', $nickId)
            ->getOneOrNullResult();

        if (null === $result || $result instanceof OperIrcop) {
            return $result;
        }

        throw new UnexpectedValueException('Authorization query did not return an IRCOP assignment.');
    }

    public function findAll(): array
    {
        /** @var list<OperIrcop> $assignments */
        $assignments = $this->em->createQuery(
            'SELECT i, r, p FROM App\OperServ\Domain\Entity\OperIrcop i JOIN i.role r LEFT JOIN r.permissions p ORDER BY i.addedAt DESC, i.id DESC'
        )->getResult();

        return $assignments;
    }

    public function findByRoleId(int $roleId): array
    {
        return $this->em->getRepository(OperIrcop::class)->findBy(['role' => $roleId], ['addedAt' => 'DESC', 'id' => 'DESC']);
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
                'SELECT COUNT(i.id) FROM App\OperServ\Domain\Entity\OperIrcop i WHERE i.role = :roleId'
            )
            ->setParameter('roleId', $roleId)
            ->getSingleScalarResult();
    }

    public function deleteByNickId(int $nickId): void
    {
        $this->em
            ->createQuery(
                'DELETE FROM App\OperServ\Domain\Entity\OperIrcop i WHERE i.nickId = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }

    public function clearAddedById(int $nickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE App\OperServ\Domain\Entity\OperIrcop i SET i.addedById = NULL WHERE i.addedById = :nickId'
            )
            ->setParameter('nickId', $nickId)
            ->execute();
    }
}
