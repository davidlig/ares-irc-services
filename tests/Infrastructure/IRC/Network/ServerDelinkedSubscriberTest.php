<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\ServerDelinkedSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ServerDelinkedSubscriber::class)]
final class ServerDelinkedSubscriberTest extends TestCase
{
    private NetworkUserRepositoryInterface&MockObject $userRepository;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private ServerDelinkedSubscriber $subscriber;

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

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $events = ServerDelinkedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ServerDelinkedEvent::class, $events);
    }

    #[Test]
    public function onServerDelinkedDispatchesQuitEventsForUsersOnServer(): void
    {
        $user1 = $this->createUser('001ABC123', 'Alice', '002');
        $user2 = $this->createUser('001DEF456', 'Bob', '002');
        $user3 = $this->createUser('001GHI789', 'Charlie', '003');

        $this->userRepository->expects(self::once())
            ->method('all')
            ->willReturn([$user1, $user2, $user3]);

        $event = new ServerDelinkedEvent('002', 'Server split');

        $this->eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::callback(static function (UserQuitNetworkEvent $quitEvent) use ($user1, $user2): bool {
                static $index = 0;
                $expectedUids = [$user1->uid->value, $user2->uid->value];
                $result = $quitEvent->uid->value === $expectedUids[$index];
                ++$index;

                return $result;
            }));

        $this->logger->expects(self::once())
            ->method('info');

        $this->subscriber->onServerDelinked($event);
    }

    #[Test]
    public function onServerDelinkedDoesNothingWhenNoAffectedUsers(): void
    {
        $user1 = $this->createUser('001ABC123', 'Alice', '003');

        $this->userRepository->expects(self::once())
            ->method('all')
            ->willReturn([$user1]);

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
            ->method('all')
            ->willReturn([$user]);

        $event = new ServerDelinkedEvent('002', '');

        $this->eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (UserQuitNetworkEvent $quitEvent): bool => '*.net *.split' === $quitEvent->reason));

        $this->subscriber->onServerDelinked($event);
    }

    #[Test]
    public function onServerDelinkedDoesNothingWhenUserListEmpty(): void
    {
        $this->userRepository->expects(self::once())
            ->method('all')
            ->willReturn([]);

        $event = new ServerDelinkedEvent('002', 'Server split');

        $this->eventDispatcher->expects(self::never())
            ->method('dispatch');

        $this->logger->expects(self::never())
            ->method('info');

        $this->subscriber->onServerDelinked($event);
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
