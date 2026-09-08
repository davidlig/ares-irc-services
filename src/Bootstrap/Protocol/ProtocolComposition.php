<?php

declare(strict_types=1);

namespace App\Bootstrap\Protocol;

use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbConnectionPreflight;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbModule;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Connect\ConnectionPreflightResult;
use App\Irc\Application\Connect\ProtocolConnectionPreflightInterface;
use InvalidArgumentException;

use function implode;
use function sprintf;

/** Selects every concrete protocol collaborator at the composition root. */
final readonly class ProtocolComposition implements ProtocolConnectionPreflightInterface
{
    /** @var array<string, ProtocolRuntimeModuleInterface> */
    private array $modules;

    /** @var array<string, NetworkStateAdapterInterface> */
    private array $networkStateAdapters;

    /**
     * @param iterable<ProtocolRuntimeModuleInterface> $modules
     * @param iterable<NetworkStateAdapterInterface>   $networkStateAdapters
     */
    public function __construct(
        private string $protocolName,
        iterable $modules,
        iterable $networkStateAdapters,
        private UnrealUdbConnectionPreflight $unrealUdbPreflight,
    ) {
        $moduleMap = [];
        foreach ($modules as $module) {
            $moduleMap[$module->getProtocolName()] = $module;
        }
        $this->modules = $moduleMap;

        $adapterMap = [];
        foreach ($networkStateAdapters as $adapter) {
            $adapterMap[$adapter->getSupportedProtocol()] = $adapter;
        }
        $this->networkStateAdapters = $adapterMap;
    }

    public function protocolModule(): ProtocolRuntimeModuleInterface
    {
        return $this->modules[$this->protocolName] ?? throw new InvalidArgumentException(sprintf('No protocol module is registered for "%s". Available: %s.', $this->protocolName, implode(', ', array_keys($this->modules))));
    }

    public function networkStateAdapter(): NetworkStateAdapterInterface
    {
        return $this->networkStateAdapters[$this->protocolName] ?? throw new InvalidArgumentException(sprintf('No network state adapter is registered for "%s". Available: %s.', $this->protocolName, implode(', ', array_keys($this->networkStateAdapters))));
    }

    public function prepare(): ConnectionPreflightResult
    {
        if (UnrealUdbModule::PROTOCOL_NAME === $this->protocolName) {
            return $this->unrealUdbPreflight->prepare();
        }

        return new ConnectionPreflightResult(true);
    }
}
