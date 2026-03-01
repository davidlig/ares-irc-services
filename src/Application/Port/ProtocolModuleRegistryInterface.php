<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ProtocolModuleRegistryInterface
{
    public function get(string $protocolName): ProtocolModuleInterface;

    public function has(string $protocolName): bool;

    /**
     * @return string[]
     */
    public function getRegisteredProtocolNames(): array;
}
