<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Domain\Entity\Gline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;
use function strtolower;

/** Final Doctrine adapter for every OperServ GLINE persistence capability. */
final readonly class DoctrineGlineRepository implements GlineRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByMask(string $mask): ?GlineEntry
    {
        $gline = $this->entityManager->getRepository(Gline::class)->findOneBy(['mask' => $mask]);

        return $gline instanceof Gline ? $this->entry($gline) : null;
    }

    public function findAll(): array
    {
        /** @var list<mixed> $glines */
        $glines = $this->entityManager->getRepository(Gline::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->entries($glines);
    }

    public function findByMaskPattern(string $pattern): array
    {
        /** @var list<mixed> $glines */
        $glines = $this->entityManager
            ->createQuery(sprintf('SELECT g FROM %s g WHERE LOWER(g.mask) LIKE :pattern ORDER BY g.createdAt DESC', Gline::class))
            ->setParameter('pattern', '%' . strtolower($pattern) . '%')
            ->getResult();

        return $this->entries($glines);
    }

    public function findActiveAt(DateTimeImmutable $at): array
    {
        /** @var list<mixed> $glines */
        $glines = $this->entityManager
            ->createQuery(sprintf('SELECT g FROM %s g WHERE g.expiresAt IS NULL OR g.expiresAt > :at ORDER BY g.createdAt DESC', Gline::class))
            ->setParameter('at', $at)
            ->getResult();

        return $this->entries($glines);
    }

    public function findExpiredAt(DateTimeImmutable $at): array
    {
        /** @var list<mixed> $glines */
        $glines = $this->entityManager
            ->createQuery(sprintf('SELECT g FROM %s g WHERE g.expiresAt IS NOT NULL AND g.expiresAt <= :at ORDER BY g.createdAt DESC', Gline::class))
            ->setParameter('at', $at)
            ->getResult();

        return $this->entries($glines);
    }

    public function countAll(): int
    {
        return (int) $this->entityManager
            ->createQuery(sprintf('SELECT COUNT(g.id) FROM %s g', Gline::class))
            ->getSingleScalarResult();
    }

    public function save(string $mask, ?int $creatorAccountId, string $reason, DateTimeImmutable $createdAt, ?DateTimeImmutable $expiresAt): void
    {
        $this->entityManager->persist(Gline::create($createdAt, $mask, $creatorAccountId, $reason, $expiresAt));
        $this->entityManager->flush();
    }

    public function remove(GlineEntry $entry): void
    {
        $gline = null !== $entry->id
            ? $this->entityManager->find(Gline::class, $entry->id)
            : $this->entityManager->getRepository(Gline::class)->findOneBy(['mask' => $entry->mask]);
        if (!$gline instanceof Gline) {
            return;
        }

        $this->entityManager->remove($gline);
        $this->entityManager->flush();
    }

    public function clearCreatorAccountId(int $accountId): void
    {
        $this->entityManager
            ->createQuery(sprintf('UPDATE %s g SET g.creatorNickId = NULL WHERE g.creatorNickId = :accountId', Gline::class))
            ->setParameter('accountId', $accountId)
            ->execute();
    }

    private function entry(Gline $gline): GlineEntry
    {
        $id = $gline->getId();
        if (0 >= $id) {
            throw new LogicException('Persisted GLINE entry must have an identifier.');
        }

        return new GlineEntry(
            $gline->getMask(),
            $gline->getCreatorNickId(),
            $gline->getReason(),
            $gline->getCreatedAt(),
            $gline->getExpiresAt(),
            $id,
        );
    }

    /** @param list<mixed> $glines
     * @return list<GlineEntry>
     */
    private function entries(array $glines): array
    {
        return array_values(array_map(
            $this->entry(...),
            array_filter($glines, static fn (mixed $gline): bool => $gline instanceof Gline),
        ));
    }
}
