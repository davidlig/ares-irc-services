<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerProviderInterface;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;

final readonly class ActiveUdbRawCommandHandlerProvider implements UdbRawCommandHandlerProviderInterface
{
    public function __construct(private ActiveConnectionHolderInterface $connectionHolder) {}

    public function getActiveHandler(): ?UdbRawCommandHandlerInterface
    {
        $module = $this->connectionHolder->getProtocolModule();

        return $module instanceof UdbRawCommandHandlerInterface ? $module : null;
    }
}
