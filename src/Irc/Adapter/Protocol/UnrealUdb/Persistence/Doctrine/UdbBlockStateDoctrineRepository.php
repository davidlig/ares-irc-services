<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UdbBlockStateDoctrineRepository implements UdbBlockStateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function all(): array
    {
        /** @var array<mixed> $states */
        $states = $this->em
            ->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s')
            ->getResult();

        $this->em->clear();

        /** @var array<string, UdbBlockState> $byBlock */
        $byBlock = [];
        foreach ($states as $state) {
            if ($state instanceof UdbBlockState) {
                $byBlock[$state->getBlock()] = $state;
            }
        }

        return $byBlock;
    }

    public function upsert(string $block, string $checksum): void
    {
        $existing = $this->em
            ->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s WHERE s.block = :block')
            ->setParameter('block', $block)
            ->getOneOrNullResult();

        if ($existing instanceof UdbBlockState) {
            $existing->update($checksum);
        } else {
            $this->em->persist(new UdbBlockState($block, $checksum));
        }

        $this->em->flush();
        $this->em->clear();
    }
}
