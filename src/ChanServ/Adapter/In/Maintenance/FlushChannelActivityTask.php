<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;

/**
 * Flushes deferred channel activity before inactive-channel expiry runs.
 *
 * A chunk the buffer could not write while the database does not answer makes this task fail, so
 * the inactive-channel purge that declares it as a prerequisite is skipped for the cycle. A single
 * row that keeps failing stays pending (its channel is kept from expiry) without failing the task.
 */
final readonly class FlushChannelActivityTask implements MaintenanceTaskInterface
{
    public const string NAME = 'chanserv.flush_channel_activity';

    public function __construct(
        private ChannelActivityBuffer $activityBuffer,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function getIntervalSeconds(): int
    {
        return 0;
    }

    public function getOrder(): int
    {
        return 299;
    }

    public function run(): void
    {
        $this->activityBuffer->flush();
    }
}
