<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

/**
 * Contract for periodic maintenance tasks executed from the IRC event loop.
 *
 * Tasks are collected by MaintenanceScheduler and executed sequentially
 * in ascending order (getOrder()). Gaps of 100 between values leave room
 * for future tasks to be inserted without renumbering.
 *
 * If a task throws, the scheduler stops the current cycle to avoid leaving
 * data in an inconsistent state.
 */
interface MaintenanceTaskInterface
{
    /**
     * Unique task identifier used for logging and last-run tracking.
     * Convention: 'service.description'  e.g. 'nickserv.purge_expired_pending'
     */
    public function getName(): string;

    /**
     * Minimum number of seconds between executions.
     * Configurable via environment variable per task.
     */
    public function getIntervalSeconds(): int;

    /**
     * Execution order within a maintenance cycle.
     * Lower values run first. Use multiples of 100 to leave insertion room.
     *
     * Suggested ranges:
     *   100–199  NickServ token / pending cleanup
     *   200–299  NickServ account expiry
     *   300–399  ChanServ orphan / access cleanup
     *   400–499  MemoServ cleanup
     *   500–599  Final deletions with cross-service dependencies resolved
     */
    public function getOrder(): int;

    public function run(): void;
}
