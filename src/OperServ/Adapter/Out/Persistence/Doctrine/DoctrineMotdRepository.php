<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use App\OperServ\Domain\Entity\Motd;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_filter;
use function array_map;
use function array_values;

/** Doctrine translation for the legacy mapped MOTD aggregate. */
final readonly class DoctrineMotdRepository implements MotdRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function add(
        string $text,
        string $botNickname,
        string $messageType,
        ?int $creatorAccountId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): MotdEntry {
        $motd = Motd::create($text, $botNickname, $messageType, $creatorAccountId, $expiresAt, $createdAt);
        $this->entityManager->persist($motd);
        $this->entityManager->flush();

        return $this->entry($motd);
    }

    public function findById(int $id): ?MotdEntry
    {
        $motd = $this->entityManager->find(Motd::class, $id);

        return $motd instanceof Motd ? $this->entry($motd) : null;
    }

    public function findAll(): array
    {
        /** @var list<mixed> $motds */
        $motds = $this->entityManager->getRepository(Motd::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->entries($motds);
    }

    public function findExpiredAt(DateTimeImmutable $at): array
    {
        /** @var list<mixed> $motds */
        $motds = $this->entityManager
            ->createQuery(
                'SELECT m FROM App\\Domain\\OperServ\\Entity\\Motd m
                 WHERE m.expiresAt IS NOT NULL AND m.expiresAt <= :at
                 ORDER BY m.createdAt ASC',
            )
            ->setParameter('at', $at)
            ->getResult();

        return $this->entries($motds);
    }

    public function remove(MotdEntry $entry): void
    {
        $motd = $this->entityManager->find(Motd::class, $entry->id);
        if (!$motd instanceof Motd) {
            return;
        }

        $this->entityManager->remove($motd);
        $this->entityManager->flush();
    }

    private function entry(Motd $motd): MotdEntry
    {
        $id = $motd->getId();
        if (null === $id) {
            throw new LogicException('Persisted MOTD entry must have an identifier.');
        }

        return new MotdEntry(
            $id,
            $motd->getText(),
            $motd->getBotNickname(),
            $motd->getMessageType(),
            $motd->isEnabled(),
            $motd->getCreatedAt(),
            $motd->getExpiresAt(),
            $motd->getShownCount(),
        );
    }

    /** @param list<mixed> $motds
     * @return list<MotdEntry>
     */
    private function entries(array $motds): array
    {
        return array_values(array_map(
            $this->entry(...),
            array_filter($motds, static fn (mixed $motd): bool => $motd instanceof Motd),
        ));
    }
}
