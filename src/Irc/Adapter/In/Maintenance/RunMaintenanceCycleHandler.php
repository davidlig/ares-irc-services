<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\RunMaintenanceCycle;

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
