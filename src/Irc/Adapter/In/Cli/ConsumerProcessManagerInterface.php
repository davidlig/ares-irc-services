<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Cli;

interface ConsumerProcessManagerInterface
{
    public function start(): void;

    public function stop(): void;
}
