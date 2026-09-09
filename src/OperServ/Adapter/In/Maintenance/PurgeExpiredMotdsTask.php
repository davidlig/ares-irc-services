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

use function sprintf;

final readonly class PurgeExpiredMotdsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private MotdRepository $motdRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $defaultLanguage,
        private string $defaultTimezone,
        private int $intervalSeconds,
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
        $expired = $this->motdRepository->findExpiredAt(new DateTimeImmutable());

        foreach ($expired as $motd) {
            $motdId = $motd->id;

            $this->debugNotifier->notify($this->formatFinalizedMessage($motd));
            $this->motdRepository->remove($motd);

            $this->logger->info(sprintf(
                'Maintenance [%s]: removed expired MOTD id %d.',
                $this->getName(),
                $motdId,
            ));
        }
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
