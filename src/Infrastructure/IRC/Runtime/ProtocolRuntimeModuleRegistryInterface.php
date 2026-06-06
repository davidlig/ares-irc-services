<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

interface ProtocolRuntimeModuleRegistryInterface
{
    public function get(string $protocolName): ProtocolRuntimeModuleInterface;

    public function has(string $protocolName): bool;

    /**
     * @return string[]
     */
    public function getRegisteredProtocolNames(): array;
}
