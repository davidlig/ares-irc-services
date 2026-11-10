<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\Entity\MotdRecord;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdCounterFlusher;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;

/** Final Doctrine adapter for every OperServ MOTD persistence capability. */
final class DoctrineMotdRepository implements MotdRepository, MotdCounterFlusher
{
    /** @var array<int, int> */
    private array $pendingShown = [];

    private bool $flushingShown = false;

    /** @var list<MotdEntry>|null */
    private ?array $activeMotds = null;

    private ?DateTimeImmutable $activeAt = null;

    private ?DateTimeImmutable $nextExpiry = null;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function add(
        string $text,
        string $botNickname,
        MessageDelivery $delivery,
        ?int $creatorAccountId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): MotdEntry {
        $motd = MotdRecord::create(
            $text,
            $botNickname,
            $this->storageValue($delivery),
            $creatorAccountId,
            $createdAt,
            $expiresAt,
        );
        $this->entityManager->persist($motd);
        $this->entityManager->flush();
        $this->invalidateActive();

        return $this->entry($motd);
    }

    public function findById(int $id): ?MotdEntry
    {
        $motd = $this->entityManager->find(MotdRecord::class, $id);

        return $motd instanceof MotdRecord ? $this->withPendingCounts([$this->entry($motd)])[0] : null;
    }

    public function findAll(): array
    {
        /** @var list<mixed> $motds */
        $motds = $this->entityManager->getRepository(MotdRecord::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->withPendingCounts($this->entries($motds));
    }

    public function findActiveAt(DateTimeImmutable $at): array
    {
        if (null !== $this->activeMotds && null !== $this->activeAt
            && $at >= $this->activeAt && (null === $this->nextExpiry || $at < $this->nextExpiry)) {
            return $this->withPendingCounts($this->activeMotds);
        }

        /** @var list<mixed> $motds */
        $motds = $this->entityManager
            ->createQuery(sprintf(
                'SELECT m FROM %s m
                 WHERE m.enabled = true AND (m.expiresAt IS NULL OR m.expiresAt > :at)
                 ORDER BY m.createdAt ASC',
                MotdRecord::class,
            ))
            ->setParameter('at', $at)
            ->getResult();

        $entries = $this->entries($motds);
        $this->activeAt = $at;
        $this->nextExpiry = null;
        foreach ($entries as $entry) {
            if (null !== $entry->expiresAt && (null === $this->nextExpiry || $entry->expiresAt < $this->nextExpiry)) {
                $this->nextExpiry = $entry->expiresAt;
            }
        }
        $this->activeMotds = $entries;

        return $this->withPendingCounts($entries);
    }

    public function findExpiredAt(DateTimeImmutable $at): array
    {
        $this->invalidateActive();
        /** @var list<mixed> $motds */
        $motds = $this->entityManager
            ->createQuery(sprintf(
                'SELECT m FROM %s m
                 WHERE m.expiresAt IS NOT NULL AND m.expiresAt <= :at
                 ORDER BY m.createdAt ASC',
                MotdRecord::class,
            ))
            ->setParameter('at', $at)
            ->getResult();

        return $this->withPendingCounts($this->entries($motds));
    }

    public function remove(MotdEntry $entry): void
    {
        $motd = $this->entityManager->find(MotdRecord::class, $entry->id);
        if (!$motd instanceof MotdRecord) {
            return;
        }

        $this->entityManager->remove($motd);
        $this->entityManager->flush();
        unset($this->pendingShown[$entry->id]);
        $this->invalidateActive();
    }

    public function recordShown(int $id): void
    {
        $this->pendingShown[$id] = ($this->pendingShown[$id] ?? 0) + 1;
    }

    public function flushShownCounts(): void
    {
        if ($this->flushingShown || [] === $this->pendingShown) {
            return;
        }
        $this->flushingShown = true;
        try {
            $this->invalidateActive();
            foreach ($this->pendingShown as $id => $count) {
                $updated = $this->entityManager->getConnection()->executeStatement(
                    'UPDATE motd SET shown_count = shown_count + ? WHERE id = ?',
                    [$count, $id],
                );
                $remaining = $this->pendingShown[$id] - $count;
                if (0 < $remaining) {
                    $this->pendingShown[$id] = $remaining;
                } else {
                    unset($this->pendingShown[$id]);
                }
                if (0 === $updated) {
                    continue;
                }
                $motd = $this->entityManager->find(MotdRecord::class, $id);
                if ($motd instanceof MotdRecord) {
                    $this->entityManager->refresh($motd);
                }
            }
        } finally {
            $this->flushingShown = false;
        }
    }

    public function deleteByCreatorAccountId(int $accountId): void
    {
        $this->entityManager
            ->createQuery(sprintf('DELETE FROM %s m WHERE m.creatorNickId = :accountId', MotdRecord::class))
            ->setParameter('accountId', $accountId)
            ->execute();
        $this->invalidateActive();
    }

    private function invalidateActive(): void
    {
        $this->activeMotds = null;
        $this->activeAt = null;
        $this->nextExpiry = null;
    }

    private function entry(MotdRecord $motd): MotdEntry
    {
        return new MotdEntry(
            $motd->getId(),
            $motd->getText(),
            $motd->getBotNickname(),
            $this->delivery($motd->getMessageType()),
            $motd->isEnabled(),
            $motd->getCreatedAt(),
            $motd->getExpiresAt(),
            $motd->getShownCount(),
            $motd->getCreatorNickId(),
        );
    }

    /** @param list<MotdEntry> $entries
     * @return list<MotdEntry>
     */
    private function withPendingCounts(array $entries): array
    {
        return array_map(fn (MotdEntry $entry): MotdEntry => new MotdEntry(
            $entry->id,
            $entry->text,
            $entry->botNickname,
            $entry->delivery,
            $entry->enabled,
            $entry->createdAt,
            $entry->expiresAt,
            $entry->shownCount + ($this->pendingShown[$entry->id] ?? 0),
            $entry->creatorAccountId,
        ), $entries);
    }

    private function storageValue(MessageDelivery $delivery): string
    {
        return match ($delivery) {
            MessageDelivery::NonInteractive => 'NOTICE',
            MessageDelivery::Interactive => 'PRIVMSG',
        };
    }

    private function delivery(string $messageType): MessageDelivery
    {
        return match ($messageType) {
            'PRIVMSG' => MessageDelivery::Interactive,
            default => MessageDelivery::NonInteractive,
        };
    }

    /** @param list<mixed> $motds
     * @return list<MotdEntry>
     */
    private function entries(array $motds): array
    {
        return array_values(array_map(
            $this->entry(...),
            array_filter($motds, static fn (mixed $motd): bool => $motd instanceof MotdRecord),
        ));
    }
}
