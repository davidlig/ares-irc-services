<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;

use function sprintf;

final readonly class PurgePendingDeletionNicknamesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickDropService $dropService,
        private int $intervalSeconds,
        private int $dropGraceDays,
    ) {}

    public function getName(): string
    {
        return 'nickserv.purge_pending_deletion_nicknames';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 210;
    }

    public function run(): void
    {
        $threshold = new DateTimeImmutable()->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $expired = $this->nickRepository->findPendingDeletionBefore($threshold);

        foreach ($expired as $nick) {
            // @phpstan-ignore instanceof.alwaysTrue
            if (!$nick instanceof RegisteredNick) {
                continue;
            }

            $this->dropService->hardDropNick($nick, 'manual-grace-expired', null);
        }
    }
}
