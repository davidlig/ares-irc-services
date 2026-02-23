<?php

declare(strict_types=1);

namespace App\Application\Maintenance\Message;

use App\Application\Maintenance\MaintenanceScheduler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunMaintenanceCycleHandler
{
    public function __construct(
        private readonly MaintenanceScheduler $maintenanceScheduler,
    ) {
    }

    public function __invoke(RunMaintenanceCycle $message): void
    {
        $this->maintenanceScheduler->tick();
    }
}
