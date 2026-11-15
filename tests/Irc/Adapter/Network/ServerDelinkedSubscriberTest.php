<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\ServerDelinkedSubscriber;
use App\Irc\Application\PublishedEvent\ServerDelinkFinishedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkStartedEvent;
use App\Irc\Domain\Event\ServerDelinkedEvent;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ServerDelinkedSubscriber::class)]
final class ServerDelinkedSubscriberTest extends TestCase
{
    private MockObject&NetworkUserRepositoryInterface $userRepository;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private ServerDelinkedSubscriber $subscriber;

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $this->userRepository->expects(self::never())->method('findByServerSids');
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->logger->expects(self::never())->method('info');

        $events = ServerDelinkedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ServerDelinkedEvent::class, $events);
    }

    #[Test]
    public function onServerDelinkedDispatchesQuitEventsForUsersOnServer(): void
    {
        $user1 = $this->createUser('001ABC123', 'Alice', '002');
        $user2 = $this->createUser('001DEF456', 'Bob', '002');

        $this->userRepository->expects(self::once())
            ->method('findByServerSids')
            ->with(['002'])
            ->willReturn([$user1, $user2]);

        $dispatched = $this->recordDispatches(4);
        $this->logger->expects(self::once())
            ->method('info');

        $this->subscriber->onServerDelinked(new ServerDelinkedEvent('002', 'Server split'));

        self::assertSame([
            [ServerDelinkStartedEvent::class, '002'],
            [UserQuitReceivedEvent::class, '001ABC123', 'Server split'],
            [UserQuitReceivedEvent::class, '001DEF456', 'Server split'],
            [ServerDelinkFinishedEvent::class, '002'],
        ], $this->describe($dispatched));
    }

    #[Test]
    public function onServerDelinkedDispatchesQuitEventsForMultipleSplitServers(): void
    {
        $user1 = $this->createUser('001ABC123', 'Alice', '002');
        $user2 = $this->createUser('001DEF456', 'Bob', '003');

        $this->userRepository->expects(self::once())
            ->method('findByServerSids')
            ->with(['002', '003'])
            ->willReturn([$user1, $user2]);

        $dispatched = $this->recordDispatches(4);
        $this->logger->expects(self::once())
            ->method('info');

        $this->subscriber->onServerDelinked(new ServerDelinkedEvent('002', 'Server split', ['002', '003']));

        self::assertSame([
            [ServerDelinkStartedEvent::class, '002'],
            [UserQuitReceivedEvent::class, '001ABC123', 'Server split'],
            [UserQuitReceivedEvent::class, '001DEF456', 'Server split'],
            [ServerDelinkFinishedEvent::class, '002'],
        ], $this->describe($dispatched));
    }

    #[Test]
    public function onServerDelinkedClosesTheDelinkWhenAQuitListenerFails(): void
    {
        $user = $this->createUser('001ABC123', 'Alice', '002');
        $this->userRepository->expects(self::once())->method('findByServerSids')->willReturn([$user]);
        $this->logger->expects(self::once())->method('info');
        $dispatched = new ArrayObject();
        $this->eventDispatcher->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($dispatched): object {
                $dispatched->append($event);
                if ($event instanceof UserQuitReceivedEvent) {
                    throw new RuntimeException('quit listener failed');
                }

                return $event;
            });

        try {
            $this->subscriber->onServerDelinked(new ServerDelinkedEvent('002', 'Server split'));
            self::fail('The quit listener failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('quit listener failed', $exception->getMessage());
        }

        self::assertSame([
            [ServerDelinkStartedEvent::class, '002'],
            [UserQuitReceivedEvent::class, '001ABC123', 'Server split'],
            [ServerDelinkFinishedEvent::class, '002'],
        ], $this->describe($dispatched));
    }

    #[Test]
    public function onServerDelinkedDoesNothingWhenNoAffectedUsers(): void
    {
        $this->userRepository->expects(self::once())
            ->method('findByServerSids')
            ->with(['002'])
            ->willReturn([]);

        $event = new ServerDelinkedEvent('002', 'Server split');

        $this->eventDispatcher->expects(self::never())
            ->method('dispatch');

        $this->logger->expects(self::never())
            ->method('info');

        $this->subscriber->onServerDelinked($event);
    }

    #[Test]
    public function onServerDelinkedUsesDefaultReasonWhenEmpty(): void
    {
        $user = $this->createUser('001ABC123', 'Alice', '002');

        $this->userRepository->expects(self::once())
            ->method('findByServerSids')
            ->with(['002'])
            ->willReturn([$user]);

        $event = new ServerDelinkedEvent('002', '');

        $dispatched = $this->recordDispatches(3);
        $this->logger->expects(self::once())->method('info');

        $this->subscriber->onServerDelinked($event);

        self::assertSame([
            [ServerDelinkStartedEvent::class, '002'],
            [UserQuitReceivedEvent::class, '001ABC123', '*.net *.split'],
            [ServerDelinkFinishedEvent::class, '002'],
        ], $this->describe($dispatched));
    }

    #[Test]
    public function onServerDelinkedDoesNothingWhenUserListEmpty(): void
    {
        $this->userRepository->expects(self::once())
            ->method('findByServerSids')
            ->with(['002'])
            ->willReturn([]);

        $event = new ServerDelinkedEvent('002', 'Server split');

        $this->eventDispatcher->expects(self::never())
            ->method('dispatch');

        $this->logger->expects(self::never())
            ->method('info');

        $this->subscriber->onServerDelinked($event);
    }

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(NetworkUserRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subscriber = new ServerDelinkedSubscriber(
            $this->userRepository,
            $this->eventDispatcher,
            $this->logger,
        );
    }

    /** @return ArrayObject<int, object> */
    private function recordDispatches(int $count): ArrayObject
    {
        $dispatched = new ArrayObject();
        $this->eventDispatcher->expects(self::exactly($count))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($dispatched): object {
                $dispatched->append($event);

                return $event;
            });

        return $dispatched;
    }

    /**
     * @param ArrayObject<int, object> $dispatched
     *
     * @return list<array<int, string>>
     */
    private function describe(ArrayObject $dispatched): array
    {
        $described = [];
        foreach ($dispatched as $event) {
            $described[] = match (true) {
                $event instanceof UserQuitReceivedEvent => [$event::class, $event->sourceId, $event->reason],
                $event instanceof ServerDelinkStartedEvent, $event instanceof ServerDelinkFinishedEvent => [$event::class, $event->serverSid],
                default => [$event::class],
            };
        }

        return $described;
    }

    private function createUser(string $uid, string $nick, string $serverSid): NetworkUser
    {
        return new NetworkUser(
            new Uid($uid),
            new Nick($nick),
            new Ident('user'),
            'hostname.example.com',
            'cloaked.example.com',
            'vhost.example.com',
            '+i',
            new DateTimeImmutable(),
            'Real Name',
            $serverSid,
            'base64ip',
        );
    }
}
