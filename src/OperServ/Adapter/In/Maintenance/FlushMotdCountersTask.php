<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\OperServ\Application\Port\Out\MotdCounterFlusher;

final readonly class FlushMotdCountersTask implements MaintenanceTaskInterface
{
    public const string NAME = 'operserv.flush_motd_counters';

    public function __construct(private MotdCounterFlusher $flusher) {}

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
        return 364;
    }

    public function run(): void
    {
        $this->flusher->flushShownCounts();
    }
}
