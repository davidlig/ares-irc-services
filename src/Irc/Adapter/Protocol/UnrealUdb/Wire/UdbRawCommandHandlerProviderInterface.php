<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

interface UdbRawCommandHandlerProviderInterface
{
    public function getActiveHandler(): ?UdbRawCommandHandlerInterface;
}
