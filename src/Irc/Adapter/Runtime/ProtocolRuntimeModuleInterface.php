<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;

interface ProtocolRuntimeModuleInterface extends ProtocolModuleInterface
{
    public function getHandler(): ProtocolHandlerInterface;
}
