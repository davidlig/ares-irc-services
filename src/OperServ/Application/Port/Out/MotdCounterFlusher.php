<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

/** Persists buffered MOTD delivery statistics. */
interface MotdCounterFlusher
{
    public function flushShownCounts(): void;
}
