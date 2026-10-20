<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\OperServ\Adapter\In\Event\OperServGeoipEnforceSubscriber;
use App\OperServ\Application\Port\Out\GeoipCountryEntry;
use App\OperServ\Application\Port\Out\GeoipCountryRepository;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stringable;

#[CoversClass(OperServGeoipEnforceSubscriber::class)]
final class OperServGeoipEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsNetworkSyncComplete(): void
    {
        $events = OperServGeoipEnforceSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NetworkSynchronizationCompletedEvent::class, $events);
    }

    #[Test]
    public function onSyncCompletePublishesEveryPersistedCountry(): void
    {
        $repository = $this->createStub(GeoipCountryRepository::class);
        $repository->method('findAll')->willReturn([
            new GeoipCountryEntry('ES', 'Not allowed', 'Oper', new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 1),
            new GeoipCountryEntry('NG', 'Blocked', 'Oper', new DateTimeImmutable('2026-10-21T10:00:00+00:00'), 2),
        ]);

        $published = [];
        $network = $this->createMock(GlineNetworkActions::class);
        $network->expects(self::exactly(2))->method('addByMask')
            ->willReturnCallback(static function (string $mask, string $reason) use (&$published): void {
                $published[] = [$mask, $reason];
            });
        $network->expects(self::never())->method('removeByMask');

        $logger = new class extends NullLogger {
            public bool $infoLogged = false;

            public function info(string|Stringable $message, array $context = []): void
            {
                $this->infoLogged = true;
            }
        };

        $subscriber = new OperServGeoipEnforceSubscriber($repository, $network, $logger);

        $subscriber->onSyncComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertSame([['~country:ES', 'Not allowed'], ['~country:NG', 'Blocked']], $published);
        self::assertTrue($logger->infoLogged);
    }

    #[Test]
    public function onSyncCompleteWithoutBlockedCountriesPublishesNothing(): void
    {
        $repository = $this->createStub(GeoipCountryRepository::class);
        $repository->method('findAll')->willReturn([]);

        $network = $this->createMock(GlineNetworkActions::class);
        $network->expects(self::never())->method('addByMask');

        $subscriber = new OperServGeoipEnforceSubscriber($repository, $network, new NullLogger());

        $subscriber->onSyncComplete(new NetworkSynchronizationCompletedEvent('001'));
    }
}
