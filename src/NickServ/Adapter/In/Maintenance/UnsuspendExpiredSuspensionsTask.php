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

    private readonly ClosedEntityManagerRetryBudget $retryBudget;

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
    ) {
        $this->retryBudget = new ClosedEntityManagerRetryBudget($this->logger, $this->getName());
    }

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
     *
     * A failure that closes the EntityManager stops the batch with the cursor kept before the
     * suspension, so the next cycle retries it; after {@see ClosedEntityManagerRetryBudget::MAX_ATTEMPTS} such
     * failures in a row while the database answers, the suspension is skipped until the next sweep, so
     * it cannot block later rows; in later sweeps it gets one attempt before it is skipped again, and
     * a database outage never skips it.
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
                $id = $nick->getId();

                try {
                    $nick->unsuspend();
                    $this->nickRepository->save($nick);
                } catch (Throwable $e) {
                    $this->recordSaveFailure($nick, $e);
                    $this->cursor = max($this->cursor, $id);
                    $this->retryBudget->rowSettled($id);

                    continue;
                }

                // Advanced only after a row-specific outcome: a closed EntityManager rethrows above,
                // so the next cycle retries this suspension instead of skipping it.
                $this->cursor = max($this->cursor, $id);
                $this->retryBudget->rowSettled($id);

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
     * A closed EntityManager makes every later row fail too, so the batch stops and the task fails;
     * the suspension is retried next cycle until it has closed the EntityManager too many times in a row.
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordSaveFailure(RegisteredNick $nick, Throwable $e): void
    {
        $id = $nick->getId();
        $this->retryBudget->rethrowWhenClosed(
            $id,
            $this->entityManager,
            $e,
            sprintf('failed to auto-unsuspend nick %s (id %d)', $nick->getNickname(), $id),
            function () use ($id): void {
                $this->cursor = max($this->cursor, $id);
            },
        );

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
