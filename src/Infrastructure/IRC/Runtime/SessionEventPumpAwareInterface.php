<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

/**
 * Optional interface for protocol handlers that need access to the session event pump
 * to serialize asynchronous protocol operations (e.g. deadline timers, mutation flushes).
 */
interface SessionEventPumpAwareInterface
{
    public function setEventPump(?SessionEventPump $eventPump): void;
}
