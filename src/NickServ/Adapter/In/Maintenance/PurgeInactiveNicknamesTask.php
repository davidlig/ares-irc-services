<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * Removes REGISTERED nicknames that have been inactive for more than the configured days.
 * Uses NickDropService for proper cleanup (event dispatch, force rename if online, etc).
 *
 * Order 200: NickServ account expiry range.
 */
final readonly class PurgeInactiveNicknamesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickDropService $dropService,
        private LoggerInterface $logger,
        private Clock $clock,
        private int $intervalSeconds,
        private int $inactivityExpiryDays,
        private ?EntityManagerInterface $entityManager = null,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

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

        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $batches = 0;
        /** @var array<int, true> $failed nick ids that failed earlier in this cycle */
        $failed = [];

        do {
            // Failed rows stay in the result set; widen the page so they never take a slot.
            $limit = $this->batchSize + count($failed);
            $batch = $this->nickRepository->findRegisteredInactiveSince($threshold, $limit);
            if ([] === $batch) {
                break;
            }

            foreach ($batch as $nick) {
                // @phpstan-ignore instanceof.alwaysTrue
                if (!$nick instanceof RegisteredNick || isset($failed[$nick->getId()])) {
                    continue;
                }

                try {
                    $this->dropService->dropNick($nick, $now, 'inactivity', null);
                } catch (Throwable $e) {
                    $failed[$nick->getId()] = true;
                    $this->logger->error(sprintf(
                        'Maintenance [%s]: failed to drop inactive nick %s (id %d); skipping it until the next cycle.',
                        $this->getName(),
                        $nick->getNickname(),
                        $nick->getId(),
                    ), ['exception' => $e]);
                }
            }

            $this->entityManager?->clear();
            ++$batches;
        } while (count($batch) === $limit && $batches < $this->maxBatchesPerCycle);
    }
}
