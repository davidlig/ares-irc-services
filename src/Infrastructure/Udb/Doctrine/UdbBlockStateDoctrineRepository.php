<?php

declare(strict_types=1);

namespace App\Infrastructure\Udb\Doctrine;

use App\Domain\Udb\Entity\UdbBlockState;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UdbBlockStateDoctrineRepository implements UdbBlockStateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function all(): array
    {
        $states = $this->em
            ->createQuery('SELECT s FROM App\Domain\Udb\Entity\UdbBlockState s')
            ->getResult();

        $this->em->clear();

        $byBlock = [];
        foreach ($states as $state) {
            $byBlock[$state->getBlock()] = $state;
        }

        return $byBlock;
    }

    public function upsert(string $block, string $checksum): void
    {
        $existing = $this->em
            ->createQuery('SELECT s FROM App\Domain\Udb\Entity\UdbBlockState s WHERE s.block = :block')
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
