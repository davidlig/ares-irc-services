<?php

declare(strict_types=1);

namespace App\Bootstrap\Maintenance\Message;

/**
 * Signal to run one maintenance cycle (dispatched synchronously to ensure IRC connection access).
 */
final readonly class RunMaintenanceCycle {}
