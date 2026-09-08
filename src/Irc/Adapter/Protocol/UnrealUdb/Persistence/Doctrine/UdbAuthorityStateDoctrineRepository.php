<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UdbAuthorityStateDoctrineRepository implements UdbAuthorityStateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function isApproved(): bool
    {
        return $this->state()->isApproved();
    }

    public function state(): UdbAuthorityState
    {
        $state = $this->em->find(UdbAuthorityState::class, 1);

        if ($state instanceof UdbAuthorityState) {
            return $state;
        }

        $state = new UdbAuthorityState();
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    public function approve(string $fingerprint): void
    {
        $state = $this->state();
        $state->approve($fingerprint);
        $this->em->flush();
        $this->em->clear();
    }

    public function revoke(): void
    {
        $state = $this->state();
        $state->revoke();
        $this->em->flush();
        $this->em->clear();
    }
}
