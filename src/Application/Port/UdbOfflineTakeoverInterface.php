<?php

declare(strict_types=1);

namespace App\Application\Port;

interface UdbOfflineTakeoverInterface
{
    public function takeover(string $directory, bool $apply = true): string;
}
