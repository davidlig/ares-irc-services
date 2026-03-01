<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolModuleRegistryInterface;
use InvalidArgumentException;

use function sprintf;

/**
 * Registry of protocol modules (Unreal, InspIRCd, etc.). Built from tagged modules;
 * no hardcoded list of IRCd types in code.
 */
final readonly class ProtocolModuleRegistry implements ProtocolModuleRegistryInterface
{
    /** @var array<string, ProtocolModuleInterface> */
    private array $modules;

    /**
     * @param iterable<ProtocolModuleInterface> $modules
     */
    public function __construct(iterable $modules)
    {
        $map = [];
        foreach ($modules as $module) {
            $map[$module->getProtocolName()] = $module;
        }
        $this->modules = $map;
    }

    public function get(string $protocolName): ProtocolModuleInterface
    {
        $module = $this->modules[$protocolName] ?? null;
        if (null === $module) {
            throw new InvalidArgumentException(sprintf('No protocol module registered for "%s". Available: %s.', $protocolName, implode(', ', $this->getRegisteredProtocolNames())));
        }

        return $module;
    }

    public function has(string $protocolName): bool
    {
        return isset($this->modules[$protocolName]);
    }

    public function getRegisteredProtocolNames(): array
    {
        return array_keys($this->modules);
    }
}
