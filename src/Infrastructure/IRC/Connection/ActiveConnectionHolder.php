<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Singleton-scoped service that stores the active S2S connection.
 *
 * Populated from NetworkBurstCompleteEvent so that any service (NickServBot,
 * ChanServBot, …) can call writeLine() without needing a direct reference to
 * the IRCClient connection.
 *
 * Also exposed as an event subscriber so the DI container wires it
 * automatically without extra configuration.
 */
class ActiveConnectionHolder implements EventSubscriberInterface
{
    private ?ConnectionInterface $connection = null;

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->connection = $event->connection;
    }

    public function getConnection(): ?ConnectionInterface
    {
        return $this->connection;
    }

    public function writeLine(string $line): void
    {
        $this->connection?->writeLine($line);
    }

    public function isConnected(): bool
    {
        return null !== $this->connection;
    }
}
