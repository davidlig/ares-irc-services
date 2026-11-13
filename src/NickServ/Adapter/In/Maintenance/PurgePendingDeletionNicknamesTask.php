<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use Doctrine\ORM\EntityManagerInterface;

use function count;
use function max;
use function sprintf;

final readonly class PurgePendingDeletionNicknamesTask implements MaintenanceTaskInterface
{
    private const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickDropService $dropService,
        private Clock $clock,
        private int $intervalSeconds,
        private int $dropGraceDays,
        private ?EntityManagerInterface $entityManager = null,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
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
        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $batches = 0;

        do {
            $expired = $this->nickRepository->findPendingDeletionBefore($threshold, $this->batchSize);
            if ([] === $expired) {
                break;
            }

            foreach ($expired as $nick) {
                // @phpstan-ignore instanceof.alwaysTrue
                if (!$nick instanceof RegisteredNick) {
                    continue;
                }

                $this->dropService->hardDropNick($nick, $now, 'manual-grace-expired', null);
            }

            $this->entityManager?->clear();
            ++$batches;
        } while (count($expired) === $this->batchSize && $batches < $this->maxBatchesPerCycle);
    }
}
