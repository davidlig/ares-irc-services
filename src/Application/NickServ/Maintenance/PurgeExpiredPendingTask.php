<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes PENDING nick registrations whose verification token has expired.
 *
 * Order 100: runs first in the NickServ maintenance range so that expired
 * pending entries are gone before any subsequent task might reference them.
 */
final readonly class PurgeExpiredPendingTask implements MaintenanceTaskInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
    ) {
    }

    public function getName(): string
    {
        return 'nickserv.purge_expired_pending';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 100;
    }

    public function run(): void
    {
        $deleted = $this->nickRepository->deleteExpiredPending();

        if ($deleted > 0) {
            $this->logger->info(
                sprintf('Maintenance [%s]: purged %d expired pending registration(s).', $this->getName(), $deleted),
            );
        }
    }
}
