<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function sprintf;

/**
 * Removes expired MOTDs in batches ordered by expiry, then id, using a keyset cursor.
 * The finalized notice to the debug channel is best-effort: when it fails, the failure
 * is logged on its own and the expired MOTD is still removed, so an unavailable debug
 * channel never keeps expired rows forever.
 *
 * A cycle handles at most maxBatchesPerCycle batches, so a mass expiry cannot block the
 * event loop; the keyset cursor is kept between cycles, so the next cycle continues where
 * this one stopped, and a short page ends the sweep. The cursor only advances once a batch
 * was deleted.
 *
 * Requires the MOTD shown-counter flush (order 364) to have succeeded in the same
 * cycle, so the finalized notice reports a current shown count.
 */
final class PurgeExpiredMotdsTask implements PrerequisiteAwareMaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public const int DEFAULT_MAX_BATCHES = 10;

    /** Last MOTD visited by the current sweep; null starts a new sweep. */
    private ?MotdEntry $cursor = null;

    public function __construct(
        private readonly MotdRepository $motdRepository,
        private readonly ServiceDebugNotifierInterface $debugNotifier,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly string $defaultLanguage,
        private readonly string $defaultTimezone,
        private readonly int $intervalSeconds,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

    public function getName(): string
    {
        return 'operserv.purge_expired_motds';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 365;
    }

    public function getPrerequisiteTaskNames(): array
    {
        return [FlushMotdCountersTask::NAME];
    }

    public function run(): void
    {
        $occurredAt = new \DateTimeImmutable();
        $batches = 0;

        do {
            $after = $this->cursor;
            $expired = $this->motdRepository->findExpiredAt($occurredAt, $after, $this->batchSize);
            $ids = [];
            foreach ($expired as $motd) {
                $after = $motd;
                $this->notifyFinalized($motd);
                $ids[] = $motd->id;
            }

            if ([] !== $ids) {
                $this->motdRepository->deleteByIds($ids);
            }

            ++$batches;
            if (count($expired) < $this->batchSize) {
                $this->cursor = null;

                return;
            }
            $this->cursor = $after;
        } while ($batches < $this->maxBatchesPerCycle);
    }

    private function notifyFinalized(MotdEntry $motd): void
    {
        try {
            $this->debugNotifier->notify($this->formatFinalizedMessage($motd));
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Maintenance [%s]: could not post the finalized notice for expired MOTD id %d to the debug channel; removing it anyway.',
                $this->getName(),
                $motd->id,
            ), ['exception' => $e]);
        }

        $this->logger->info(sprintf(
            'Maintenance [%s]: removed expired MOTD id %d.',
            $this->getName(),
            $motd->id,
        ));
    }

    private function formatFinalizedMessage(MotdEntry $motd): string
    {
        $timezone = new \DateTimeZone($this->defaultTimezone);
        $date = ($motd->expiresAt ?? $motd->createdAt)
            ->setTimezone($timezone)
            ->format('d/m/Y H:i T');

        return $this->translator->trans('motd.debug.finalized', [
            '%id%' => (string) $motd->id,
            '%type%' => match ($motd->delivery) {
                MessageDelivery::NonInteractive => 'NOTICE',
                MessageDelivery::Interactive => 'PRIVMSG',
            },
            '%message%' => $motd->text,
            '%date%' => $date,
            '%shown_count%' => $this->translator->trans('motd.list.shown_count', [
                '%count%' => (string) $motd->shownCount,
            ], 'operserv', $this->defaultLanguage),
        ], 'operserv', $this->defaultLanguage);
    }
}
