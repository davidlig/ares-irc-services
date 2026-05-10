<?php

declare(strict_types=1);

namespace App\Application\OperServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

final readonly class PurgeExpiredMotdsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private MotdRepositoryInterface $motdRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $defaultLanguage,
        private string $defaultTimezone,
        private int $intervalSeconds,
    ) {
    }

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
        $expired = $this->motdRepository->findExpired();

        foreach ($expired as $motd) {
            $motdId = $motd->getId();

            $this->debugNotifier->notify($this->formatFinalizedMessage($motd));
            $this->motdRepository->remove($motd);

            $this->logger->info(sprintf(
                'Maintenance [%s]: removed expired MOTD id %d.',
                $this->getName(),
                $motdId,
            ));
        }
    }

    private function formatFinalizedMessage(Motd $motd): string
    {
        $timezone = new DateTimeZone($this->defaultTimezone);
        $date = ($motd->getExpiresAt() ?? $motd->getCreatedAt())
            ->setTimezone($timezone)
            ->format('d/m/Y H:i T');

        return $this->translator->trans('motd.debug.finalized', [
            '%id%' => (string) $motd->getId(),
            '%type%' => $motd->getMessageType(),
            '%message%' => $motd->getText(),
            '%date%' => $date,
            '%shown_count%' => $this->translator->trans('motd.list.shown_count', [
                '%count%' => (string) $motd->getShownCount(),
            ], 'operserv', $this->defaultLanguage),
        ], 'operserv', $this->defaultLanguage);
    }
}
