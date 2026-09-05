<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Application\Port\UdbRawCommandHandlerProviderInterface;

final readonly class ActiveUdbRawCommandHandlerProvider implements UdbRawCommandHandlerProviderInterface
{
    public function __construct(private ActiveConnectionHolderInterface $connectionHolder) {}

    public function getActiveHandler(): ?UdbRawCommandHandlerInterface
    {
        $module = $this->connectionHolder->getProtocolModule();

        return $module instanceof UdbRawCommandHandlerInterface ? $module : null;
    }
}
