<?php

declare(strict_types=1);

namespace App\Application\OperServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class PurgeExpiredGlinesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private GlineRepositoryInterface $glineRepository,
        private LoggerInterface $logger,
        private readonly int $intervalSeconds,
    ) {
    }

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

            $this->logger->info(sprintf(
                'Maintenance [%s]: removed expired GLINE %s (id %d).',
                $this->getName(),
                $mask,
                $glineId,
            ));
        }
    }
}
