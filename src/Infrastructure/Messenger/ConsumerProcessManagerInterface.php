<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

interface ConsumerProcessManagerInterface
{
    public function start(): void;

    public function stop(): void;
}
