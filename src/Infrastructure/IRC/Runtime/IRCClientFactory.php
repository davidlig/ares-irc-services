<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates IRCClient instances wired with the appropriate protocol module.
 * Sets the active module on ActiveConnectionHolder so services obtain handler,
 * formatters and actions from the module for the connected IRCd type.
 */
final readonly class IRCClientFactory implements IRCClientFactoryInterface
{
    public function __construct(
        private readonly ProtocolRuntimeModuleRegistryInterface $moduleRegistry,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly EventBusInterface $eventDispatcher,
        private readonly AsyncMessageDispatcherInterface $messageBus,
        private readonly BurstCompleteRegistry $burstCompleteRegistry,
        private readonly int $maintenanceDispatchIntervalSeconds,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(string $protocolName, ServerLink $link): IRCClient
    {
        $module = $this->moduleRegistry->get($protocolName);
        $this->connectionHolder->setProtocolModule($module);
        $connection = $this->connectionFactory->create($link);

        return new IRCClient(
            $connection,
            $module->getHandler(),
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            $this->maintenanceDispatchIntervalSeconds,
            $this->logger,
        );
    }
}
