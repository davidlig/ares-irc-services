<?php

declare(strict_types=1);

namespace App\Application\IRC;

use App\Application\Port\ProtocolModuleRegistryInterface;
use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates IRCClient instances wired with the appropriate protocol module.
 * Sets the active module on ActiveConnectionHolder so services obtain handler,
 * formatters and actions from the module for the connected IRCd type.
 */
class IRCClientFactory
{
    public function __construct(
        private readonly ProtocolModuleRegistryInterface $moduleRegistry,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messageBus,
        private readonly BurstCompleteRegistry $burstCompleteRegistry,
        private readonly int $maintenanceDispatchIntervalSeconds,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

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
