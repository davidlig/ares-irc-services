<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

interface UdbOfflineTakeoverInterface
{
    public function takeover(string $directory, bool $apply = true): string;
}
