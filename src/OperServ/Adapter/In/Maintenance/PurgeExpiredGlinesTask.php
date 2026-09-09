<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use LogicException;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class PurgeExpiredGlinesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private GlineRepository $glineRepository,
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
        $occurredAt = new DateTimeImmutable();
        $expired = $this->glineRepository->findExpiredAt($occurredAt);

        foreach ($expired as $gline) {
            $mask = $gline->mask;
            $glineId = $gline->id;
            if (null === $glineId) {
                throw new LogicException('Persisted GLINE entry must have an identifier.');
            }

            $this->glineRepository->remove($gline);

            $this->eventDispatcher->dispatch(new GlineRemovedEvent(
                glineId: $glineId,
                mask: $mask,
                removedBy: $this->serverName,
                cause: 'expired',
                occurredAt: $occurredAt,
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
