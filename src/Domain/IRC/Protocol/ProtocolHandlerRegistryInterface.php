<?php

declare(strict_types=1);

namespace App\Domain\IRC\Protocol;

interface ProtocolHandlerRegistryInterface
{
    public function register(ProtocolHandlerInterface $handler): void;

    public function get(string $protocolName): ProtocolHandlerInterface;

    public function supports(string $protocolName): bool;

    /**
     * @return string[]
     */
    public function getRegisteredProtocols(): array;
}
