<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Replaces a closed Doctrine EntityManager before the next unit of work runs.
 *
 * Doctrine closes an EntityManager for good when a flush fails, and every later
 * call on that instance throws EntityManagerClosed. The injected EntityManager
 * service is lazy, so ManagerRegistry::resetManager() swaps the instance behind
 * the same proxy every holder already references: the repositories never need to
 * be rebuilt nor re-fetched.
 */
final readonly class EntityManagerRecovery
{
    public function __construct(
        private ManagerRegistry $registry,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function recover(): void
    {
        $manager = $this->registry->getManager();

        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            return;
        }

        $this->registry->resetManager();

        $this->logger->warning('Recovered a closed Doctrine EntityManager after a previous failure.');
    }
}
