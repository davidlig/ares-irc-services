<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Application\NickServ\Service\NickDropService;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;

use function sprintf;

/**
 * Removes REGISTERED nicknames that have been inactive for more than the configured days.
 * Uses NickDropService for proper cleanup (event dispatch, force rename if online, etc).
 *
 * Order 200: NickServ account expiry range.
 */
final readonly class PurgeInactiveNicknamesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickDropService $dropService,
        private readonly int $intervalSeconds,
        private readonly int $inactivityExpiryDays,
    ) {
    }

    public function getName(): string
    {
        return 'nickserv.purge_inactive_nicknames';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 200;
    }

    public function run(): void
    {
        if ($this->inactivityExpiryDays <= 0) {
            return;
        }

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $inactive = $this->nickRepository->findRegisteredInactiveSince($threshold);

        foreach ($inactive as $nick) {
            if (!$nick instanceof RegisteredNick) {
                continue;
            }

            $this->dropService->dropNick($nick, 'inactivity', null);
        }
    }
}
