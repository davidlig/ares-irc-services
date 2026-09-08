<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

interface UdbOfflineTakeoverInterface
{
    public function takeover(string $directory, bool $apply = true): string;
}
