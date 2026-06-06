<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

interface ProtocolRuntimeModuleInterface extends ProtocolModuleInterface
{
    public function getHandler(): ProtocolHandlerInterface;
}
