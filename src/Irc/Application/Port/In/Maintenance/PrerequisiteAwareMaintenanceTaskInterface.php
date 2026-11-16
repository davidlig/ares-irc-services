<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In\Maintenance;

/**
 * Optional contract for a maintenance task that depends on other tasks of the same cycle.
 *
 * When any declared prerequisite fails in a cycle (or is itself skipped because one of its own
 * prerequisites failed), the scheduler skips this task for that cycle and retries it on the next
 * tick. A prerequisite that simply was not due this cycle does not block the task.
 *
 * Prerequisites must run earlier in the cycle, so they need a lower getOrder() value.
 */
interface PrerequisiteAwareMaintenanceTaskInterface extends MaintenanceTaskInterface
{
    /**
     * Names (see getName()) of the tasks that must not have failed earlier in the cycle.
     *
     * @return list<string>
     */
    public function getPrerequisiteTaskNames(): array;
}
