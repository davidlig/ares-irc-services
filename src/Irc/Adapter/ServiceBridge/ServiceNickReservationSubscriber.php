<?php

declare(strict_types=1);

namespace App\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\ManagedServiceNickReservationLookup;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceCommandListenerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function array_values;
use function count;
use function strtolower;

/**
 * Reconciles protocol-native service nickname reservations before pseudo-clients are introduced.
 *
 * This subscriber runs at priority 200, before bots (priority 90-100), ensuring
 * that reservations are in place to prevent nick collisions if a malicious user
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
    private const string REASON = 'Reserved for network services';

    /**
     * @param iterable<ServiceCommandListenerInterface> $serviceListeners
     */
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private NetworkUserLookupPort $userLookup,
        private iterable $serviceListeners,
        private ServiceNickReservationInventory $inventory,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

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

        $current = [];
        foreach ($this->serviceListeners as $listener) {
            $nick = $listener->getServiceName();
            $current[strtolower($nick)] = $nick;
        }

        $protocol = $module->getProtocolName();
        $hasLiveLookup = $reservation instanceof ManagedServiceNickReservationLookup;
        $previous = [];
        $canPersistInventory = true;
        try {
            foreach ($this->inventory->namesForProtocol($protocol) as $nick) {
                $previous[strtolower($nick)] = $nick;
            }
        } catch (Throwable $exception) {
            $this->logger->error('Could not load service nickname reservation inventory', [
                'protocol' => $protocol,
                'exception' => $exception->getMessage(),
            ]);
            if (!$hasLiveLookup) {
                throw $exception;
            }

            $previous = [];
            $canPersistInventory = false;
        }

        if ($hasLiveLookup) {
            // The live records, not the historical inventory, determine which
            // reservations still belong to services and may be released.
            $previous = [];
            try {
                foreach ($reservation->findManagedServiceNicks(self::REASON) as $nick) {
                    $previous[strtolower($nick)] = $nick;
                }
            } catch (Throwable $exception) {
                $this->logger->error('Could not discover managed service nickname reservations', [
                    'protocol' => $protocol,
                    'exception' => $exception->getMessage(),
                ]);
                $previous = [];
                $canPersistInventory = false;
            }
        } else {
            // Wire reservations cannot be discovered or attributed later. Keep
            // every historical name before any permanent network effect.
            try {
                $this->inventory->replaceForProtocol($protocol, array_values($previous + $current));
            } catch (Throwable $exception) {
                $this->logger->error('Could not prepare service nickname reservation inventory', [
                    'protocol' => $protocol,
                    'exception' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }

        $failedReleases = [];
        foreach ($previous as $key => $nick) {
            if (isset($current[$key])) {
                continue;
            }

            if (!$hasLiveLookup) {
                $this->logger->warning('Obsolete wire service nickname reservation requires manual review and removal; current ownership cannot be verified', [
                    'nick' => $nick,
                    'protocol' => $protocol,
                ]);

                continue;
            }

            try {
                $reservation->releaseNick($nick);
            } catch (Throwable $exception) {
                $this->logger->error('Could not release obsolete service nickname reservation', [
                    'nick' => $nick,
                    'protocol' => $protocol,
                    'exception' => $exception->getMessage(),
                ]);
                $failedReleases[$key] = $nick;
            }
        }

        $serviceActions = $module->getServiceActions();
        foreach ($current as $nick) {
            $reservation->reserveNick($nick, self::REASON);

            $this->freeServiceNickname($serviceActions, $event->serverSid, $nick);
        }

        if ($canPersistInventory && $hasLiveLookup) {
            try {
                $this->inventory->replaceForProtocol($protocol, array_values($failedReleases + $current));
            } catch (Throwable $exception) {
                $this->logger->error('Could not save service nickname reservation inventory', [
                    'protocol' => $protocol,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->logger->info('Reserved service nicknames', ['count' => count($current)]);
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
