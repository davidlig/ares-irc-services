<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Bootstrap\Maintenance\MaintenanceTaskInterface;
use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use App\OperServ\Domain\Repository\GlineRepositoryInterface;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\ServiceDebugNotifierInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class PurgeExpiredGlinesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private GlineRepositoryInterface $glineRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private EventBusInterface $eventDispatcher,
        private LoggerInterface $logger,
        private string $serverName,
        private int $intervalSeconds,
    ) {}

    public function getName(): string
    {
        return 'operserv.purge_expired_glines';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 360;
    }

    public function run(): void
    {
        $expired = $this->glineRepository->findExpired();

        foreach ($expired as $gline) {
            $mask = $gline->getMask();
            $glineId = $gline->getId();

            $this->glineRepository->remove($gline);

            $this->eventDispatcher->dispatch(new GlineRemovedEvent(
                glineId: $glineId,
                mask: $mask,
                removedBy: $this->serverName,
                cause: 'expired',
            ));

            $this->debugNotifier->log(
                operator: $this->serverName,
                command: 'GLINE DEL',
                target: $mask,
                reason: 'expired',
            );

            $this->logger->info(sprintf(
                'Maintenance [%s]: removed expired GLINE %s (id %d).',
                $this->getName(),
                $mask,
                $glineId,
            ));
        }
    }
}
