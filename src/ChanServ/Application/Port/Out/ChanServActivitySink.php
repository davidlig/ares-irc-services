<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanServActivitySink
{
    public function debug(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;
}
