<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

    /**
     * One failing split quit (for example a database error in a departure listener) is logged and
     * the remaining split users still quit, so no ghost users stay in the network state; the delink
     * is closed once at the end.
     */
    #[Test]
    public function onServerDelinkedKeepsQuittingTheRemainingUsersWhenOneQuitFails(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $users->add($this->createUser('001AAAAAA', 'Alice', '002'));
        $users->add($this->createUser('001BBBBBB', 'Bob', '002'));
        $users->add($this->createUser('001CCCCCC', 'Carol', '002'));
        $users->add($this->createUser('001DDDDDD', 'Dave', '003'));
        $failure = new \RuntimeException('departure listener failed');
        $finished = new class {
            /** @var list<string> */
            public array $serverSids = [];
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserQuitReceivedEvent::class, static function (UserQuitReceivedEvent $event) use ($users, $failure): void {
            if ('001BBBBBB' === $event->sourceId) {
                throw $failure;
            }
            $users->removeByUid(new Uid($event->sourceId));
        });
        $dispatcher->addListener(ServerDelinkFinishedEvent::class, static function (ServerDelinkFinishedEvent $event) use ($finished): void {
            $finished->serverSids[] = $event->serverSid;
        });
        $this->userRepository->expects(self::never())->method('findByServerSids');
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::once())->method('error')->with(
            'Split quit for 001BBBBBB (Bob) failed; continuing with the remaining users of server 002.',
            ['exception' => $failure],
        );

        new ServerDelinkedSubscriber($users, $dispatcher, $this->logger)
            ->onServerDelinked(new ServerDelinkedEvent('002', 'Server split'));

        self::assertNull($users->findByUid(new Uid('001AAAAAA')));
        self::assertNull($users->findByUid(new Uid('001CCCCCC')));
        self::assertNotNull($users->findByUid(new Uid('001BBBBBB')));
        self::assertNotNull($users->findByUid(new Uid('001DDDDDD')), 'Users on other servers stay.');
        self::assertSame(['002'], $finished->serverSids);
    }

    /**
     * A started listener (ChanServ opens its departure batch there) can fail after it already began
     * its work; the failure is logged, every split user still quits (no ghosts) and the finished
     * event still closes the batch.
     */
    #[Test]
    public function onServerDelinkedStillQuitsEverySplitUserWhenAStartedListenerFails(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $users->add($this->createUser('001AAAAAA', 'Alice', '002'));
        $users->add($this->createUser('001BBBBBB', 'Bob', '002'));
        $failure = new \RuntimeException('started listener failed');
        $finished = new class {
            /** @var list<string> */
            public array $serverSids = [];
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ServerDelinkStartedEvent::class, static function () use ($failure): void {
            throw $failure;
        });
        $dispatcher->addListener(UserQuitReceivedEvent::class, static function (UserQuitReceivedEvent $event) use ($users): void {
            $users->removeByUid(new Uid($event->sourceId));
        });
        $dispatcher->addListener(ServerDelinkFinishedEvent::class, static function (ServerDelinkFinishedEvent $event) use ($finished): void {
            $finished->serverSids[] = $event->serverSid;
        });
        $this->userRepository->expects(self::never())->method('findByServerSids');
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::once())->method('error')->with(
            'Delink start listener for server 002 failed; quitting its users anyway.',
            ['exception' => $failure],
        );

        new ServerDelinkedSubscriber($users, $dispatcher, $this->logger)
            ->onServerDelinked(new ServerDelinkedEvent('002', 'Server split'));

        self::assertNull($users->findByUid(new Uid('001AAAAAA')));
        self::assertNull($users->findByUid(new Uid('001BBBBBB')));
        self::assertSame(['002'], $finished->serverSids);
    }

    /**
     * When both the started and the finished listener fail, the finished failure propagates and the
     * started failure is not lost: it was already logged before the quits ran.
     */
    #[Test]
    public function onServerDelinkedLogsTheStartedFailureWhenTheFinishedListenerAlsoFails(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $users->add($this->createUser('001AAAAAA', 'Alice', '002'));
        $startedFailure = new \RuntimeException('started listener failed');
        $finishedFailure = new \RuntimeException('finished listener failed');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ServerDelinkStartedEvent::class, static function () use ($startedFailure): void {
            throw $startedFailure;
        });
        $dispatcher->addListener(UserQuitReceivedEvent::class, static function (UserQuitReceivedEvent $event) use ($users): void {
            $users->removeByUid(new Uid($event->sourceId));
        });
        $dispatcher->addListener(ServerDelinkFinishedEvent::class, static function () use ($finishedFailure): void {
            throw $finishedFailure;
        });
        $this->userRepository->expects(self::never())->method('findByServerSids');
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::once())->method('error')->with(
            'Delink start listener for server 002 failed; quitting its users anyway.',
            ['exception' => $startedFailure],
        );

        try {
            new ServerDelinkedSubscriber($users, $dispatcher, $this->logger)
                ->onServerDelinked(new ServerDelinkedEvent('002', 'Server split'));
            self::fail('The finished listener failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($finishedFailure, $exception);
        }

        self::assertNull($users->findByUid(new Uid('001AAAAAA')));
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
    private function recordDispatches(int $count): \ArrayObject
    {
        $dispatched = new \ArrayObject();
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
    private function describe(\ArrayObject $dispatched): array
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
            new \DateTimeImmutable(),
            'Real Name',
            $serverSid,
            'base64ip',
        );
    }
}
