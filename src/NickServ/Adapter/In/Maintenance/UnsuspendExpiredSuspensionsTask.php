<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

use function count;
use function sprintf;

final readonly class UnsuspendExpiredSuspensionsTask implements MaintenanceTaskInterface
{
    private const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private LoggerInterface $logger,
        private Clock $clock,
        private NickServEventPublisher $eventPublisher,
        private string $serverName,
        private int $intervalSeconds,
        private ?EntityManagerInterface $entityManager = null,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
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

    public function run(): void
    {
        $batches = 0;

        do {
            $expired = $this->nickRepository->findExpiredSuspensions($this->clock->now(), $this->batchSize);
            if ([] === $expired) {
                break;
            }

            foreach ($expired as $nick) {
                $nickname = $nick->getNickname();
                $nickId = $nick->getId();

                $nick->unsuspend();
                $this->nickRepository->save($nick);
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

            $this->entityManager?->clear();
            ++$batches;
        } while (count($expired) === $this->batchSize && $batches < $this->maxBatchesPerCycle);
    }
}
