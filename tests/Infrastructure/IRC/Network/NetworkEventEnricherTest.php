<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\SkipIdentifiedModeStripRegistryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\NetworkEventEnricher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(NetworkEventEnricher::class)]
final class NetworkEventEnricherTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsExpectedHandlers(): void
    {
        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $this->createStub(NetworkUserRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $events = $enricher->getSubscribedEvents();

        self::assertArrayHasKey(QuitReceivedEvent::class, $events);
        self::assertIsArray($events[QuitReceivedEvent::class]);
        self::assertSame('onQuitReceived', $events[QuitReceivedEvent::class][0]);
    }

    #[Test]
    public function onQuitReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $userRepo->expects(self::never())->method('removeByUid');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onQuitReceived(new QuitReceivedEvent('001ABC123', 'Leaving'));
    }

    #[Test]
    public function onQuitReceivedDispatchesLeftChannelAndQuitNetworkAndRemovesUser(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $user->addChannel(new ChannelName('#test'));

        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::atLeastOnce())->method('findByUid')->with(self::callback(static fn (Uid $u) => '001ABC123' === $u->value))->willReturn($user);
        $userRepo->expects(self::once())->method('removeByUid')->with(self::callback(static fn ($u) => $u instanceof Uid && '001ABC123' === $u->value));

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onQuitReceived(new QuitReceivedEvent('001ABC123', 'Bye'));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(UserLeftChannelEvent::class, $dispatched[0]);
        self::assertSame('001ABC123', $dispatched[0]->uid->value);
        self::assertSame('Bye', $dispatched[0]->reason);
        self::assertInstanceOf(UserQuitNetworkEvent::class, $dispatched[1]);
        self::assertSame('001ABC123', $dispatched[1]->uid->value);
        self::assertSame('Bye', $dispatched[1]->reason);
    }
}
