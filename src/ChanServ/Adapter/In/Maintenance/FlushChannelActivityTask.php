<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;

/** Flushes deferred channel activity before inactive-channel expiry runs. */
final readonly class FlushChannelActivityTask implements MaintenanceTaskInterface
{
    public function __construct(
        private ChannelActivityBuffer $activityBuffer,
    ) {}

    public function getName(): string
    {
        return 'chanserv.flush_channel_activity';
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
