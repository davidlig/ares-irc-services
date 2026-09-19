<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface AsyncMessageDispatcherInterface
{
    public function dispatch(object $message): object;
}
