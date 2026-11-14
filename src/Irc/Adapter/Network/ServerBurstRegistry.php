<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Per-server burst state of the current link, keyed by the server key the protocol
 * adapter uses for its server tree (the SID). A server is bursting from its introduction
 * (or, for the uplink, from the link start) until its own end-of-burst (UnrealIRCd EOS,
 * InspIRCd ENDBURST). Protocol adapters forget delinked servers; a lost connection
 * forgets every server.
 */
final class ServerBurstRegistry implements EventSubscriberInterface
{
    /** @var array<string, true> */
    private array $bursting = [];

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionLostEvent::class => 'clear',
        ];
    }

    public function startBurst(string $server): void
    {
        $this->bursting[$server] = true;
    }

    public function endBurst(string ...$servers): void
    {
        foreach ($servers as $server) {
            unset($this->bursting[$server]);
        }
    }

    public function isBursting(?string $server): bool
    {
        return null !== $server && isset($this->bursting[$server]);
    }

    public function clear(): void
    {
        $this->bursting = [];
    }
}
