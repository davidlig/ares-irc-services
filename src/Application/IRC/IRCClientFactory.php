<?php

declare(strict_types=1);

namespace App\Application\IRC;

use App\Application\Maintenance\MaintenanceScheduler;
use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerRegistryInterface;
use App\Domain\IRC\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates IRCClient instances wired with the appropriate protocol handler
 * and a fresh connection for a given ServerLink.
 */
class IRCClientFactory
{
    public function __construct(
        private readonly ProtocolHandlerRegistryInterface $protocolRegistry,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MaintenanceScheduler $maintenanceScheduler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(string $protocolName, ServerLink $link): IRCClient
    {
        $protocol   = $this->protocolRegistry->get($protocolName);
        $connection = $this->connectionFactory->create($link);

        return new IRCClient(
            $connection,
            $protocol,
            $this->eventDispatcher,
            $this->maintenanceScheduler,
            $this->logger,
        );
    }
}
