<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRegisterNickRepository implements RegisterNickRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByNick(string $nickname): ?RegisteredNick
    {
        return $this->entityManager
            ->getRepository(RegisteredNick::class)
            ->findOneBy(['nicknameLower' => strtolower($nickname)]);
    }

    public function findByEmail(string $email): ?RegisteredNick
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('nick')
            ->from(RegisteredNick::class, 'nick')
            ->where('LOWER(nick.email) = LOWER(:email)')
            ->andWhere('nick.email IS NOT NULL')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof RegisteredNick ? $result : null;
    }

    public function save(RegisteredNick $nick): void
    {
        $this->entityManager->persist($nick);
        $this->entityManager->flush();
    }
}
