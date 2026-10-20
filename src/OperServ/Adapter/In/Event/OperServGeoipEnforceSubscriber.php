<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\OperServ\Application\Port\Out\GeoipCountryRepository;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Domain\Entity\GeoipCountry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;

/**
 * Republishes the country gate when the IRC link is established.
 *
 * The G-lines of the IRC daemon do not survive a restart of the daemon, so the
 * persisted list is published again every time the link comes up.
 */
final readonly class OperServGeoipEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GeoipCountryRepository $countries,
        private GlineNetworkActions $network,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 0],
        ];
    }

    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $countries = $this->countries->findAll();
        if ([] === $countries) {
            return;
        }

        foreach ($countries as $country) {
            $this->network->addByMask(GeoipCountry::maskFor($country->countryCode), $country->reason);
        }

        $this->logger->info('Country gate republished after sync', [
            'count' => count($countries),
        ]);
    }
}
