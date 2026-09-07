<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickServActivitySink
{
    public function debug(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;
}
