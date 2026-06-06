<?php

declare(strict_types=1);

namespace App\Application\Port;

interface AsyncMessageDispatcherInterface
{
    public function dispatch(object $message): object;
}
