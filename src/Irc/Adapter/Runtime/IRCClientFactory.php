<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use App\Irc\Adapter\Out\Connection\ConnectionFactoryInterface;
use App\Irc\Application\BurstCompleteRegistry;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Domain\Server\ServerLink;
use App\Shared\Application\Port\AsyncMessageDispatcherInterface;
use App\Shared\Application\Port\EventBusInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates IRCClient instances wired with the protocol module selected in Bootstrap.
 * Sets the active module on ActiveConnectionHolder so services obtain handler,
 * formatters and actions from the module for the connected IRCd type.
 */
final readonly class IRCClientFactory implements IRCClientFactoryInterface
{
    public function __construct(
        private ProtocolRuntimeModuleInterface $module,
        private ConnectionFactoryInterface $connectionFactory,
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private EventBusInterface $eventDispatcher,
        private AsyncMessageDispatcherInterface $messageBus,
        private BurstCompleteRegistry $burstCompleteRegistry,
        private int $maintenanceDispatchIntervalSeconds,
        private LoggerInterface $logger = new NullLogger(),
        private LoopSchedulerInterface $loopScheduler = new RevoltLoopScheduler(),
    ) {}

    public function create(ServerLink $link): IRCClient
    {
        $this->connectionHolder->setProtocolModule($this->module);
        $connection = $this->connectionFactory->create($link);

        return new IRCClient(
            $connection,
            $this->module->getHandler(),
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            $this->maintenanceDispatchIntervalSeconds,
            $this->logger,
            $this->loopScheduler,
        );
    }
}
