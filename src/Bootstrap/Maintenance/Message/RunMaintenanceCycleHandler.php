<?php

declare(strict_types=1);

namespace App\Bootstrap\Maintenance\Message;

use App\Bootstrap\Maintenance\MaintenanceScheduler;

final readonly class RunMaintenanceCycleHandler
{
    public function __construct(
        private MaintenanceScheduler $maintenanceScheduler,
    ) {}

    public function __invoke(RunMaintenanceCycle $message): void
    {
        $this->maintenanceScheduler->tick();
    }
}
