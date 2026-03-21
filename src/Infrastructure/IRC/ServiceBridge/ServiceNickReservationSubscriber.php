<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reserves all service nicknames via SQLINE/QLINE before pseudo-clients are introduced.
 *
 * This subscriber runs at priority 200, before bots (priority 90-100), ensuring
 * that Q-lines are in place to prevent nick collisions if a malicious user
 * tries to take a service nickname during the brief window before bot introduction.
 *
 * Additionally, if a user already holds a service nickname during burst (e.g.,
 * someone connected as "MemoServ" before services linked), this subscriber will
 * KILL them to free the nick for the service bot.
 *
 * Uses ServiceCommandListenerInterface to discover all registered service nicks.
 */
final readonly class ServiceNickReservationSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ServiceCommandListenerInterface> $serviceListeners
     */
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly iterable $serviceListeners,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 200],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->warning('Cannot reserve service nicks: no protocol module');

            return;
        }

        $reservation = $module->getNickReservation();
        if (null === $reservation) {
            $this->logger->debug('Nick reservation not available for this protocol');

            return;
        }

        $serviceActions = $module->getServiceActions();

        $reservedCount = 0;
        foreach ($this->serviceListeners as $listener) {
            $nick = $listener->getServiceName();

            $reservation->reserveNick($event->connection, $event->serverSid, $nick, 'Reserved for network services');

            $this->freeServiceNickname($serviceActions, $event->serverSid, $nick);

            ++$reservedCount;
        }

        $this->logger->info('Reserved service nicknames', ['count' => $reservedCount]);
    }

    private function freeServiceNickname(
        ProtocolServiceActionsInterface $serviceActions,
        string $serverSid,
        string $nick,
    ): void {
        $existingUser = $this->userLookup->findByNick($nick);
        if (null === $existingUser) {
            return;
        }

        $this->logger->warning('User holds service nickname, killing', [
            'nick' => $nick,
            'uid' => $existingUser->uid,
        ]);

        $serviceActions->killUser($serverSid, $existingUser->uid, 'Service nickname reserved');
        $this->logger->info('Sent KILL for service nickname collision', ['nick' => $nick, 'uid' => $existingUser->uid]);
    }
}
