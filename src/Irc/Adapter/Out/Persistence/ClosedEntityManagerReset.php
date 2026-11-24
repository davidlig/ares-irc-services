<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * A database error can close the Doctrine EntityManager, which makes every later Doctrine-backed
 * operation of this process fail until it is reset. This resets a closed default EntityManager
 * (logged as a warning); the default EntityManager service is lazy, so the reset reopens it in
 * place for every service that already holds it.
 *
 * The check only asks the manager whether it is open, so it runs no query and is cheap enough to
 * call before every incoming IRC line. A failing reset is logged and never thrown.
 */
final readonly class ClosedEntityManagerReset
{
    public function __construct(private ?ManagerRegistry $managerRegistry = null) {}

    /**
     * @param string               $scope   log message prefix naming the caller (for example "Maintenance")
     * @param array<string, mixed> $context
     */
    public function resetIfClosed(LoggerInterface $logger, string $scope, array $context = []): void
    {
        if (null === $this->managerRegistry) {
            return;
        }

        try {
            $manager = $this->managerRegistry->getManager();
            if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
                return;
            }

            $logger->warning($scope . ': the EntityManager is closed, resetting it', $context);
            $this->managerRegistry->resetManager();
        } catch (\Throwable $e) {
            $logger->error($scope . ': resetting the closed EntityManager failed', $context + ['exception' => $e]);
        }
    }
}
