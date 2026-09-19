<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface EventBusInterface
{
    public function dispatch(object $event): void;
}
