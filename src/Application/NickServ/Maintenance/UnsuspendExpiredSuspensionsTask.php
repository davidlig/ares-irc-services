<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class UnsuspendExpiredSuspensionsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private LoggerInterface $logger,
        private readonly string $serverName,
        private readonly int $intervalSeconds,
    ) {
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

    public function run(): void
    {
        $expired = $this->nickRepository->findExpiredSuspensions();

        foreach ($expired as $nick) {
            $nickname = $nick->getNickname();
            $nickId = $nick->getId();

            $nick->unsuspend();
            $this->nickRepository->save($nick);

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
}
