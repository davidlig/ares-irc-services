<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\BlockedEmailDomainRepositoryInterface;
use App\NickServ\Domain\Entity\BlockedEmailDomain;
use Doctrine\ORM\EntityManagerInterface;

use function count;

final readonly class BlockedEmailDomainDoctrineRepository implements BlockedEmailDomainRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function isBlocked(string $domain): bool
    {
        return null !== $this->em->getRepository(BlockedEmailDomain::class)->findOneBy(['domain' => $domain]);
    }

    public function add(BlockedEmailDomain $blockedEmailDomain): void
    {
        $this->em->persist($blockedEmailDomain);
        $this->em->flush();
    }

    public function removeAtPosition(int $position): ?BlockedEmailDomain
    {
        if ($position < 1) {
            return null;
        }

        $entry = $this->listAll()[$position - 1] ?? null;
        if (null === $entry) {
            return null;
        }

        $this->em->remove($entry);
        $this->em->flush();

        return $entry;
    }

    public function removeAll(): int
    {
        $entries = $this->listAll();

        foreach ($entries as $entry) {
            $this->em->remove($entry);
        }

        if ([] !== $entries) {
            $this->em->flush();
        }

        return count($entries);
    }

    public function listAll(): array
    {
        return $this->em->getRepository(BlockedEmailDomain::class)->findBy([], ['domain' => 'ASC']);
    }
}
