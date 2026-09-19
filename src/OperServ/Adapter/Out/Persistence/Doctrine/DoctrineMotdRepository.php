<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\Entity\MotdRecord;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;

/** Final Doctrine adapter for every OperServ MOTD persistence capability. */
final readonly class DoctrineMotdRepository implements MotdRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

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

        return $this->entry($motd);
    }

    public function findById(int $id): ?MotdEntry
    {
        $motd = $this->entityManager->find(MotdRecord::class, $id);

        return $motd instanceof MotdRecord ? $this->entry($motd) : null;
    }

    public function findAll(): array
    {
        /** @var list<mixed> $motds */
        $motds = $this->entityManager->getRepository(MotdRecord::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->entries($motds);
    }

    public function findActiveAt(DateTimeImmutable $at): array
    {
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

        return $this->entries($motds);
    }

    public function findExpiredAt(DateTimeImmutable $at): array
    {
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

        return $this->entries($motds);
    }

    public function remove(MotdEntry $entry): void
    {
        $motd = $this->entityManager->find(MotdRecord::class, $entry->id);
        if (!$motd instanceof MotdRecord) {
            return;
        }

        $this->entityManager->remove($motd);
        $this->entityManager->flush();
    }

    public function recordShown(int $id): void
    {
        $motd = $this->entityManager->find(MotdRecord::class, $id);
        if (!$motd instanceof MotdRecord) {
            return;
        }

        $motd->recordShown();
        $this->entityManager->flush();
    }

    public function deleteByCreatorAccountId(int $accountId): void
    {
        $this->entityManager
            ->createQuery(sprintf('DELETE FROM %s m WHERE m.creatorNickId = :accountId', MotdRecord::class))
            ->setParameter('accountId', $accountId)
            ->execute();
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
