<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleRegistryInterface;
use App\Infrastructure\IRC\Runtime\RevoltLoopScheduler;
use App\Irc\Adapter\Out\Connection\ConnectionFactoryInterface;
use App\Irc\Application\BurstCompleteRegistry;
use App\Irc\Domain\Server\ServerLink;
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
        private ProtocolRuntimeModuleRegistryInterface $moduleRegistry,
        private ConnectionFactoryInterface $connectionFactory,
        private ActiveConnectionHolderInterface $connectionHolder,
        private EventBusInterface $eventDispatcher,
        private AsyncMessageDispatcherInterface $messageBus,
        private BurstCompleteRegistry $burstCompleteRegistry,
        private int $maintenanceDispatchIntervalSeconds,
        private LoggerInterface $logger = new NullLogger(),
        private LoopSchedulerInterface $loopScheduler = new RevoltLoopScheduler(),
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
            $this->loopScheduler,
        );
    }
}
