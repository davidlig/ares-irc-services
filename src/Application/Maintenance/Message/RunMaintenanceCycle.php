<?php

declare(strict_types=1);

namespace App\Application\Maintenance\Message;

/**
 * Signal to run one maintenance cycle (dispatched to async transport).
 */
final readonly class RunMaintenanceCycle
{
}
