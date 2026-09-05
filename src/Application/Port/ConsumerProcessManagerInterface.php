<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ConsumerProcessManagerInterface
{
    public function start(): void;

    public function stop(): void;
}
