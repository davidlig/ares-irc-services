<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;

final readonly class UdbBlockStateDoctrineRepository implements UdbBlockStateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function all(): array
    {
        /** @var array<string, mixed> $states */
        $states = $this->em
            ->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s INDEX BY s.block')
            ->getResult();

        $this->em->clear();

        return array_filter($states, static fn (mixed $state): bool => $state instanceof UdbBlockState);
    }

    public function upsert(
        string $block,
        string $checksum,
        int $recordCount,
        ?DateTimeImmutable $modifiedAt = null,
    ): void {
        $existing = $this->em
            ->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s WHERE s.block = :block')
            ->setParameter('block', $block)
            ->getOneOrNullResult();

        if ($existing instanceof UdbBlockState) {
            $existing->update($checksum, $recordCount, $modifiedAt);
        } else {
            $this->em->persist(new UdbBlockState($block, $checksum, $modifiedAt, $recordCount));
        }

        $this->em->flush();
        $this->em->clear();
    }
}
