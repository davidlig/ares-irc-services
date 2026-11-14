<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
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
 * Removes expired MOTDs in id-ordered batches. A MOTD whose removal keeps
 * failing is logged and left for the next cycle; the keyset cursor moves past it
 * so it never blocks the remaining rows or the later maintenance tasks.
 */
final readonly class PurgeExpiredMotdsTask implements MaintenanceTaskInterface
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

    public function run(): void
    {
        $occurredAt = new DateTimeImmutable();
        $after = null;

        do {
            $expired = $this->motdRepository->findExpiredAt($occurredAt, $after, $this->batchSize);
            $ids = [];
            foreach ($expired as $motd) {
                $after = $motd;
                if ($this->finalize($motd)) {
                    $ids[] = $motd->id;
                }
            }

            if ([] !== $ids) {
                $this->motdRepository->deleteByIds($ids);
            }
        } while (count($expired) === $this->batchSize);
    }

    private function finalize(MotdEntry $motd): bool
    {
        try {
            $this->debugNotifier->notify($this->formatFinalizedMessage($motd));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to remove expired MOTD id %d; skipping it until the next cycle.',
                $this->getName(),
                $motd->id,
            ), ['exception' => $e]);

            return false;
        }

        $this->logger->info(sprintf(
            'Maintenance [%s]: removed expired MOTD id %d.',
            $this->getName(),
            $motd->id,
        ));

        return true;
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
