<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Domain\Entity\RegisteredNick;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function max;
use function sprintf;

final class UnsuspendExpiredSuspensionsTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    /** Id of the last nick visited by the current sweep; 0 starts a new sweep. */
    private int $cursor = 0;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly ServiceDebugNotifierInterface $debugNotifier,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly NickServEventPublisher $eventPublisher,
        private readonly string $serverName,
        private readonly int $intervalSeconds,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

    public function getName(): string
    {
        return 'nickserv.unsuspend_expired_suspensions';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 195;
    }

    /**
     * Each cycle continues the sweep after the last visited id, so suspensions that keep failing
     * are skipped until the next sweep instead of taking every page.
     */
    public function run(): void
    {
        $batches = 0;

        do {
            $expired = $this->nickRepository->findExpiredSuspensions($this->clock->now(), $this->batchSize, $this->cursor);
            if ([] === $expired) {
                $this->cursor = 0;

                return;
            }

            foreach ($expired as $nick) {
                $this->cursor = max($this->cursor, $nick->getId());

                try {
                    $nick->unsuspend();
                    $this->nickRepository->save($nick);
                } catch (Throwable $e) {
                    $this->recordSaveFailure($nick, $e);

                    continue;
                }

                try {
                    $this->announce($nick);
                } catch (Throwable $e) {
                    $this->logger->error(sprintf(
                        'Maintenance [%s]: auto-unsuspended nick %s (id %d), but a post-unsuspend step failed.',
                        $this->getName(),
                        $nick->getNickname(),
                        $nick->getId(),
                    ), ['exception' => $e]);
                }
            }

            $this->entityManager?->clear();
            ++$batches;

            if (count($expired) < $this->batchSize) {
                $this->cursor = 0;

                return;
            }
        } while ($batches < $this->maxBatchesPerCycle);
    }

    /**
     * A closed EntityManager makes every later row fail too, so the batch stops and the task fails.
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordSaveFailure(RegisteredNick $nick, Throwable $e): void
    {
        if (null !== $this->entityManager && !$this->entityManager->isOpen()) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to auto-unsuspend nick %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $nick->getNickname(),
                $nick->getId(),
            ), ['exception' => $e]);

            throw $e;
        }

        // Keep the half-applied unsuspend out of later flushes in this batch.
        $this->entityManager?->detach($nick);
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to auto-unsuspend nick %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $nick->getNickname(),
            $nick->getId(),
        ), ['exception' => $e]);
    }

    private function announce(RegisteredNick $nick): void
    {
        $nickname = $nick->getNickname();
        $nickId = $nick->getId();

        $this->eventPublisher->publish(new NickUnsuspendedEvent(
            nickId: $nickId,
            nickname: $nickname,
            performedBy: $this->serverName,
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
            occurredAt: $this->clock->now(),
        ));

        $this->debugNotifier->log(
            operator: $this->serverName,
            command: 'UNSUSPEND',
            target: $nickname,
        );

        $this->logger->info(sprintf(
            'Maintenance [%s]: auto-unsuspended nick %s (id %d).',
            $this->getName(),
            $nickname,
            $nickId,
        ));
    }
}
