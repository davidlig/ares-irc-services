<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * Removes expired MOTDs in batches ordered by expiry, then id, using a keyset cursor.
 * The finalized notice to the debug channel is best-effort: when it fails, the failure
 * is logged on its own and the expired MOTD is still removed, so an unavailable debug
 * channel never keeps expired rows forever.
 *
 * Requires the MOTD shown-counter flush (order 364) to have succeeded in the same
 * cycle, so the finalized notice reports a current shown count.
 */
final readonly class PurgeExpiredMotdsTask implements PrerequisiteAwareMaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private MotdRepository $motdRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $defaultLanguage,
        private string $defaultTimezone,
        private int $intervalSeconds,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
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
        $occurredAt = new DateTimeImmutable();
        $after = null;

        do {
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
        } while (count($expired) === $this->batchSize);
    }

    private function notifyFinalized(MotdEntry $motd): void
    {
        try {
            $this->debugNotifier->notify($this->formatFinalizedMessage($motd));
        } catch (Throwable $e) {
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
        $timezone = new DateTimeZone($this->defaultTimezone);
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
