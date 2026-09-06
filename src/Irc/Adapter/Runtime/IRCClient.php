<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use Amp\Future;
use App\Application\Maintenance\Message\RunMaintenanceCycle;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use App\Infrastructure\IRC\Runtime\RevoltLoopScheduler;
use App\Infrastructure\IRC\Runtime\SessionEventPump;
use App\Infrastructure\IRC\Runtime\SessionEventPumpAwareInterface;
use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Application\BurstCompleteRegistry;
use App\Irc\Application\IrcSessionInterface;
use App\Irc\Domain\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function gc_collect_cycles;
use function max;

/**
 * Orchestrates the lifecycle of an IRC server-to-server link:
 * connect, run the read loop, and disconnect.
 */
class IRCClient implements IrcSessionInterface
{
    private ?ServerLink $activeLink = null;

    private readonly SessionEventPump $eventPump;

    private ?string $maintenanceWatcherId = null;

    private bool $maintenanceScheduled = false;

    private bool $finalized = false;

    private ?string $firstCloseReason = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ProtocolHandlerInterface $protocol,
        private readonly EventBusInterface $eventDispatcher,
        private readonly AsyncMessageDispatcherInterface $messageBus,
        private readonly BurstCompleteRegistry $burstCompleteRegistry,
        private readonly int $maintenanceDispatchIntervalSeconds,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly LoopSchedulerInterface $loopScheduler = new RevoltLoopScheduler(),
        ?SessionEventPump $eventPump = null,
    ) {
        $this->eventPump = $eventPump ?? new SessionEventPump();
        if ($this->protocol instanceof SessionEventPumpAwareInterface) {
            $this->protocol->setEventPump($this->eventPump);
        }
    }

    public function connect(ServerLink $link): void
    {
        $this->logger->info('Initiating S2S link.', [
            'server' => (string) $link->serverName,
            'host' => (string) $link->host,
            'port' => $link->port->value,
            'protocol' => $this->protocol->getProtocolName(),
            'tls' => $link->useTls,
        ]);

        if ($this->protocol instanceof SessionEventPumpAwareInterface) {
            $this->protocol->setEventPump($this->eventPump);
        }

        $this->connection->connect();
        try {
            $this->protocol->performHandshake($this->connection, $link);
        } catch (Throwable $e) {
            $this->connection->disconnect();
            if ($this->protocol instanceof SessionEventPumpAwareInterface) {
                $this->protocol->setEventPump(null);
            }
            throw $e;
        }

        $this->activeLink = $link;

        $this->eventDispatcher->dispatch(new ConnectionEstablishedEvent($link));
    }

    /**
     * Runs the non-blocking read loop using Amp and the serialized SessionEventPump.
     * Reads lines from the IRCD and processes them in strict FIFO order.
     * Exits when the connection drops, EOF is encountered, or disconnect is requested.
     */
    public function run(): void
    {
        $this->logger->info('Entering read loop.', [
            'protocol' => $this->protocol->getProtocolName(),
        ]);

        if (!$this->connection->isConnected()) {
            $this->logger->warning('Read loop cannot start: connection is not connected.');

            return;
        }

        $this->finalized = false;
        $this->firstCloseReason = null;
        $this->maintenanceScheduled = false;

        $this->checkBurstCompleteAndScheduleMaintenance();

        /** @var Future<void> $readerFuture */
        $readerFuture = async(function (): void {
            try {
                while (!$this->finalized && $this->connection->isConnected()) {
                    $rawLine = $this->connection->readLine();

                    if (null === $rawLine) {
                        break;
                    }

                    if ('' === $rawLine) {
                        continue;
                    }

                    // @phpstan-ignore if.alwaysFalse
                    if ($this->finalized) {
                        break;
                    }

                    $this->eventPump->enqueue(function () use ($rawLine): void {
                        $this->processIncomingLine($rawLine);
                    });
                }
            } catch (Throwable $e) {
                if (!$this->finalized) {
                    $this->eventPump->enqueue(static function () use ($e): void {
                        throw $e;
                    });

                    return;
                }
            }

            if (!$this->finalized) {
                $this->eventPump->enqueue(function (): void {
                    $this->finalizeSession('Remote host closed connection');
                });
            }
        });

        try {
            $this->eventPump->drain();
        } catch (Throwable $e) {
            $this->firstCloseReason ??= $e->getMessage();
            throw $e;
        } finally {
            $this->finalizeSession($this->firstCloseReason ?? 'Session terminated');
            $this->logger->warning('Read loop terminated.');
        }
    }

    public function disconnect(?string $reason = null): void
    {
        $effectiveReason = $reason ?? 'Disconnect requested';
        $this->logger->info('Disconnecting.', ['reason' => $effectiveReason]);
        $this->firstCloseReason ??= $effectiveReason;

        $this->finalizeSession($effectiveReason);
    }

    public function getProtocolName(): string
    {
        return $this->protocol->getProtocolName();
    }

    public function getEventPump(): SessionEventPump
    {
        return $this->eventPump;
    }

    private function processIncomingLine(string $rawLine): void
    {
        $message = $this->protocol->parseRawLine($rawLine);

        $this->protocol->handleIncoming($message, $this->connection);

        $this->eventDispatcher->dispatch(new MessageReceivedEvent($message));
        $this->eventDispatcher->dispatch(new IrcMessageProcessedEvent());

        $this->checkBurstCompleteAndScheduleMaintenance();
    }

    private function checkBurstCompleteAndScheduleMaintenance(): void
    {
        if ($this->maintenanceScheduled || !$this->burstCompleteRegistry->isBurstComplete()) {
            return;
        }

        $this->maintenanceScheduled = true;

        // First maintenance cycle runs immediately after burst complete without waiting
        $this->eventPump->enqueue(function (): void {
            $this->executeMaintenance();
        });

        // Subsequent maintenance runs periodically
        $interval = max(1, $this->maintenanceDispatchIntervalSeconds);
        $this->maintenanceWatcherId = $this->loopScheduler->repeat(
            (float) $interval,
            function (): void {
                $this->eventPump->enqueue(function (): void {
                    $this->executeMaintenance();
                });
            },
        );
    }

    private function executeMaintenance(): void
    {
        gc_collect_cycles();
        $this->messageBus->dispatch(new RunMaintenanceCycle());
    }

    /**
     * Single authority for finalizing the IRC session.
     * Idempotent: safe to call multiple times, cancels timers, stops event pump,
     * dispatches ConnectionLostEvent exactly once, and disconnects transport.
     */
    private function finalizeSession(string $reason, ?Throwable $exception = null): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        if (null !== $this->maintenanceWatcherId) {
            $this->loopScheduler->cancel($this->maintenanceWatcherId);
            $this->maintenanceWatcherId = null;
        }
        $this->maintenanceScheduled = false;

        $this->eventPump->stop();

        if (null !== $this->activeLink) {
            $effectiveReason = $this->firstCloseReason ?? $reason;
            $this->eventDispatcher->dispatch(
                new ConnectionLostEvent($this->activeLink, $effectiveReason)
            );
            $this->activeLink = null;
        }

        if ($this->protocol instanceof SessionEventPumpAwareInterface) {
            $this->protocol->setEventPump(null);
        }

        $this->connection->disconnect();
    }
}
