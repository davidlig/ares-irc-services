<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerRegistryInterface;
use InvalidArgumentException;

use function sprintf;

class ProtocolHandlerRegistry implements ProtocolHandlerRegistryInterface
{
    /** @var array<string, ProtocolHandlerInterface> */
    private array $handlers = [];

    /**
     * @param iterable<ProtocolHandlerInterface> $handlers tagged services injected by Symfony DI
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(ProtocolHandlerInterface $handler): void
    {
        $this->handlers[$handler->getProtocolName()] = $handler;
    }

    public function get(string $protocolName): ProtocolHandlerInterface
    {
        if (!$this->supports($protocolName)) {
            throw new InvalidArgumentException(sprintf('No protocol handler registered for "%s". Available protocols: %s.', $protocolName, implode(', ', $this->getRegisteredProtocols())));
        }

        return $this->handlers[$protocolName];
    }

    public function supports(string $protocolName): bool
    {
        return isset($this->handlers[$protocolName]);
    }

    public function getRegisteredProtocols(): array
    {
        return array_keys($this->handlers);
    }
}
