<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\ChannelSweepFailureBudget;
use App\ChanServ\Adapter\In\Maintenance\UnsuspendExpiredChannelsTask;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;
use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function in_array;

#[CoversClass(UnsuspendExpiredChannelsTask::class)]
#[CoversClass(ChannelSweepFailureBudget::class)]
final class UnsuspendExpiredChannelsTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsExpectedValue(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(EventBusInterface::class),
            new NullLogger(),
            self::SERVER_NAME,
            3600,
        );

        self::assertSame('chanserv.unsuspend_expired_channels', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(EventBusInterface::class),
            new NullLogger(),
            self::SERVER_NAME,
            7200,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns196(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(EventBusInterface::class),
            new NullLogger(),
            self::SERVER_NAME,
            3600,
        );

        self::assertSame(196, $task->getOrder());
    }

    #[Test]
    public function runUnsuspendsExpiredChannelsAndDispatchesEvents(): void
    {
        $channel1 = $this->createChannelWithId('#expired1', 1, 'Expired 1');
        $channel1->suspend('Abuse', new \DateTimeImmutable('-1 hour'));

        $channel2 = $this->createChannelWithId('#expired2', 2, 'Expired 2');
        $channel2->suspend('Spam', new \DateTimeImmutable('-2 hours'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('iterateExpiredSuspensions')
            ->willReturn([$channel1, $channel2]);
        $channelRepo->expects(self::exactly(2))->method('save');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(static fn (ChannelUnsuspendedEvent $event): ChannelUnsuspendedEvent => $event);

        $task = new UnsuspendExpiredChannelsTask(
            $channelRepo,
            $eventDispatcher,
            new NullLogger(),
            self::SERVER_NAME,
            3600,
        );
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredSuspensions(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('iterateExpiredSuspensions')
            ->willReturn([]);
        $channelRepo->expects(self::never())->method('save');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $task = new UnsuspendExpiredChannelsTask(
            $channelRepo,
            $eventDispatcher,
            new NullLogger(),
            self::SERVER_NAME,
            3600,
        );
        $task->run();
    }

    #[Test]
    public function aChannelThatFailsToUnsuspendIsLoggedAndLaterChannelsAreStillUnsuspended(): void
    {
        $failing = $this->createChannelWithId('#broken', 1, 'Broken');
        $failing->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $later = $this->createChannelWithId('#later', 2, 'Later');
        $later->suspend('Spam', new \DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturn([$failing, $later]);
        $channelRepo->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            if ('#broken' === $channel->getName()) {
                throw new \RuntimeException('constraint violation');
            }
        });
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched): ChannelUnsuspendedEvent {
            $dispatched[] = $event->channelName;

            return $event;
        });
        $errors = self::messages();
        $exceptions = self::recorder();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context) use ($errors, $exceptions): void {
            $errors[] = $message;
            $exceptions[] = $context['exception'] ?? null;
        });

        new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, $logger, self::SERVER_NAME, 3600)->run();

        self::assertSame(['#later'], $dispatched->getArrayCopy());
        self::assertCount(1, $errors);
        self::assertStringContainsString('failed to auto-unsuspend channel #broken (id 1); skipping it until the next sweep', $errors->getArrayCopy()[0] ?? '');
        self::assertInstanceOf(\RuntimeException::class, $exceptions[0]);
    }

    #[Test]
    public function aChannelThatKeepsClosingTheEntityManagerIsSkippedAfterThreeCycles(): void
    {
        $saved = self::messages();
        $open = true;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnCallback(function (): array {
            $poison = $this->createChannelWithId('#poison', 1, 'Poison');
            $poison->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
            $later = $this->createChannelWithId('#later', 2, 'Later');
            $later->suspend('Spam', new \DateTimeImmutable('-1 hour'));

            return [$poison, $later];
        });
        $channelRepo->method('save')->willReturnCallback(static function (RegisteredChannel $channel) use ($saved, &$open): void {
            $saved[] = $channel->getName();
            if ('#poison' === $channel->getName()) {
                $open = false;

                throw new \RuntimeException('deadlock');
            }
        });
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $this->createStub(EventBusInterface::class), $logger, self::SERVER_NAME, 3600, $entityManager);

        for ($cycle = 1; $cycle <= 3; ++$cycle) {
            $open = true;
            $failure = null;
            try {
                $task->run();
            } catch (\Throwable $e) {
                $failure = $e;
            }
            self::assertInstanceOf(\RuntimeException::class, $failure);
        }
        $open = true;
        $task->run();

        self::assertSame(['#poison', '#poison', '#poison', '#later'], $saved->getArrayCopy());
        self::assertStringContainsString('failed to auto-unsuspend channel #poison (id 1); the EntityManager is closed, stopping this batch', $errors->getArrayCopy()[0] ?? '');
        self::assertStringContainsString('closed 3 times in a row; skipping it until the next sweep', $errors->getArrayCopy()[2] ?? '');
    }

    #[Test]
    public function aFailureAfterTheUnsuspensionCommittedIsReportedAndTheEventIsDispatchedAgainNextCycle(): void
    {
        $channel = $this->createChannelWithId('#restored', 1, 'Restored');
        $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $later = $this->createChannelWithId('#later', 2, 'Later');
        $later->suspend('Spam', new \DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$channel, $later], [], []);
        $channelRepo->method('findByIds')->willReturn([$channel]);
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched): ChannelUnsuspendedEvent {
            $dispatched[] = $event->channelName;
            if (1 === $dispatched->count()) {
                throw new \RuntimeException('UDB write failed');
            }

            return $event;
        });
        $errors = self::messages();
        $infos = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $logger->method('info')->willReturnCallback(static function (string $message) use ($infos): void {
            $infos[] = $message;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, $logger, self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();

        self::assertSame(['#restored', '#later'], $dispatched->getArrayCopy());
        self::assertCount(1, $errors);
        self::assertStringContainsString('auto-unsuspended channel #restored (id 1), but a post-unsuspend step failed; dispatching its unsuspension again next cycle', $errors->getArrayCopy()[0] ?? '');

        $task->run();
        $task->run();

        self::assertSame(['#restored', '#later', '#restored'], $dispatched->getArrayCopy(), 'the event is dispatched again once, then forgotten');
        self::assertStringContainsString('dispatched the unsuspension of channel #restored (id 1) again', $infos->getArrayCopy()[1] ?? '');
    }

    #[Test]
    public function aPostUnsuspendStepThatKeepsFailingIsGivenUpAfterThreeMoreAttempts(): void
    {
        $channel = $this->createChannelWithId('#restored', 1, 'Restored');
        $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$channel], [], [], [], []);
        $channelRepo->method('findByIds')->willReturn([$channel]);
        $dispatches = 0;
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function () use (&$dispatches): never {
            ++$dispatches;

            throw new \RuntimeException('UDB write failed');
        });
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, $logger, self::SERVER_NAME, 3600, $this->openEntityManager());

        for ($cycle = 1; $cycle <= 5; ++$cycle) {
            $task->run();
        }

        self::assertSame(1 + UnsuspendExpiredChannelsTask::MAX_UNSUSPENSION_DISPATCH_RETRIES, $dispatches);
        self::assertCount(4, $errors);
        self::assertStringContainsString('failed to dispatch the unsuspension of channel #restored (id 1) again; trying again next cycle', $errors->getArrayCopy()[1] ?? '');
        self::assertStringContainsString('failed to dispatch the unsuspension of channel #restored (id 1) again 3 times; giving up', $errors->getArrayCopy()[3] ?? '');
    }

    #[Test]
    public function theUnsuspensionIsNotDispatchedAgainForAChannelThatWasDroppedOrSuspendedAgain(): void
    {
        $dropped = $this->createChannelWithId('#dropped', 1, 'Dropped');
        $dropped->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $resuspended = $this->createChannelWithId('#resuspended', 2, 'Resuspended');
        $resuspended->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$dropped, $resuspended], [], []);
        $channelRepo->method('findByIds')->willReturnCallback(static fn (array $ids): array => in_array(2, $ids, true) ? [$resuspended] : []);
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched): never {
            $dispatched[] = $event->channelName;

            throw new \RuntimeException('UDB write failed');
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();
        $resuspended->suspend('Abuse again', null);
        $task->run();
        $task->run();

        self::assertSame(['#dropped', '#resuspended'], $dispatched->getArrayCopy());
    }

    #[Test]
    public function aPostUnsuspendFailureThatClosedTheEntityManagerStopsTheCycleAndIsDispatchedAgainLater(): void
    {
        $channel = $this->createChannelWithId('#restored', 1, 'Restored');
        $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $later = $this->createChannelWithId('#later', 2, 'Later');
        $later->suspend('Spam', new \DateTimeImmutable('-1 hour'));
        $open = true;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$channel, $later], [$later], [], []);
        $channelRepo->method('findByIds')->willReturn([$channel]);
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched, &$open): ChannelUnsuspendedEvent {
            $dispatched[] = $event->channelName;
            if (in_array($dispatched->count(), [1, 3], true)) {
                $open = false;

                throw new \RuntimeException('deadlock');
            }

            return $event;
        });
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, $logger, self::SERVER_NAME, 3600, $entityManager);

        self::assertInstanceOf(\RuntimeException::class, $this->runCatching($task), 'a closed EntityManager stops the cycle');
        self::assertSame(['#restored'], $dispatched->getArrayCopy());
        self::assertStringContainsString('auto-unsuspended channel #restored (id 1), but a post-unsuspend step failed; dispatching its unsuspension again next cycle and stopping this batch', $errors->getArrayCopy()[0] ?? '');

        $open = true;
        self::assertInstanceOf(\RuntimeException::class, $this->runCatching($task), 'a repeated dispatch that closes the EntityManager fails the cycle');
        self::assertSame(['#restored', '#later', '#restored'], $dispatched->getArrayCopy(), 'the stopped batch continues first, then the event is dispatched again');

        $open = true;
        $task->run();
        $task->run();

        self::assertSame(['#restored', '#later', '#restored', '#restored'], $dispatched->getArrayCopy());
        self::assertStringContainsString('failed to dispatch the unsuspension of channel #restored (id 1) again; trying again next cycle and stopping this batch', $errors->getArrayCopy()[1] ?? '');
    }

    #[Test]
    public function aRetriedDispatchIsMarkedAsARetrySoOnlyTheRestorationRunsAgain(): void
    {
        $channel = $this->createChannelWithId('#restored', 1, 'Restored');
        $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$channel], [], []);
        $channelRepo->method('findByIds')->willReturn([$channel]);
        /** @var ArrayObject<int, ChannelUnsuspendedEvent> $events */
        $events = new \ArrayObject();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($events): ChannelUnsuspendedEvent {
            $events[] = $event;
            if (2 > $events->count()) {
                throw new \RuntimeException('UDB write failed');
            }

            return $event;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();
        $task->run();
        $task->run();

        $flags = array_map(static fn (ChannelUnsuspendedEvent $event): bool => $event->isRetry, $events->getArrayCopy());
        self::assertSame([false, true], $flags, 'history and the debug notice see the first dispatch only');
        [$first, $retry] = $events->getArrayCopy();
        self::assertSame($first->occurredAt, $retry->occurredAt);
        self::assertSame('#restored', $retry->channelNameLower);
    }

    #[Test]
    public function thePendingChannelsOfACycleAreLoadedWithOneQuery(): void
    {
        $first = $this->createChannelWithId('#first', 1, 'First');
        $first->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $second = $this->createChannelWithId('#second', 2, 'Second');
        $second->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
        $loads = self::recorder();
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls([$first, $second], [], []);
        $channelRepo->method('findByIds')->willReturnCallback(static function (array $ids) use ($loads, $first, $second): array {
            $loads[] = $ids;

            return [$second, $first];
        });
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched): ChannelUnsuspendedEvent {
            $dispatched[] = $event->channelName;
            if (2 >= $dispatched->count()) {
                throw new \RuntimeException('UDB write failed');
            }

            return $event;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();
        self::assertSame([], $loads->getArrayCopy(), 'nothing is pending in the first cycle');

        $task->run();
        $task->run();

        self::assertSame([[1, 2]], $loads->getArrayCopy(), 'one query loads every pending channel, and none is left afterwards');
        self::assertSame(['#first', '#second', '#first', '#second'], $dispatched->getArrayCopy());
    }

    #[Test]
    public function aPendingChannelThatFailsAgainIsRememberedAsTheNewestOne(): void
    {
        $channels = [];
        for ($id = 1; $id <= UnsuspendExpiredChannelsTask::MAX_PENDING_UNSUSPENSION_DISPATCHES + 1; ++$id) {
            $channel = $this->createChannelWithId('#chan' . $id, $id, 'Channel');
            $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
            $channels[$id] = $channel;
        }
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls(
            [$channels[1]],
            [$channels[2]],
            array_values(array_slice($channels, 2, null, true)),
        );
        $channelRepo->method('findByIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_filter(
                $channels,
                static fn (RegisteredChannel $channel): bool => in_array($channel->getId(), $ids, true),
            )),
        );
        $retried = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($retried): never {
            if ($event->isRetry) {
                $retried[] = $event->channelName;
            }

            throw new \RuntimeException('UDB write failed');
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();
        $task->run();
        self::assertSame(['#chan1'], $retried->getArrayCopy(), '#chan1 failed again after #chan2 was added');

        $task->run();

        self::assertSame(['#chan1', '#chan1'], $retried->getArrayCopy(), 'the oldest pending channel is #chan2, so it is the one forgotten');
    }

    #[Test]
    public function theOldestPendingDispatchIsForgottenSoMemoryStaysBounded(): void
    {
        $channels = [];
        for ($id = 1; $id <= UnsuspendExpiredChannelsTask::MAX_PENDING_UNSUSPENSION_DISPATCHES + 1; ++$id) {
            $channel = $this->createChannelWithId('#chan' . $id, $id, 'Channel');
            $channel->suspend('Abuse', new \DateTimeImmutable('-1 hour'));
            $channels[$id] = $channel;
        }
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturnOnConsecutiveCalls(array_values($channels), []);
        $channelRepo->method('findByIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_filter(
                $channels,
                static fn (RegisteredChannel $channel): bool => in_array($channel->getId(), $ids, true),
            )),
        );
        $failing = new \ArrayObject([true]);
        $dispatched = self::messages();
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use ($dispatched, $failing): ChannelUnsuspendedEvent {
            if (true === $failing[0]) {
                throw new \RuntimeException('UDB write failed');
            }
            $dispatched[] = $event->channelName;

            return $event;
        });
        $task = new UnsuspendExpiredChannelsTask($channelRepo, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, $this->openEntityManager());

        $task->run();
        $failing[0] = false;
        $task->run();

        self::assertCount(UnsuspendExpiredChannelsTask::MAX_PENDING_UNSUSPENSION_DISPATCHES, $dispatched);
        self::assertSame('#chan2', $dispatched->getArrayCopy()[0] ?? null, 'the oldest pending channel was forgotten');
    }

    private function openEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);

        return $entityManager;
    }

    private function runCatching(UnsuspendExpiredChannelsTask $task): ?\Throwable
    {
        try {
            $task->run();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function createChannelWithId(string $name, int $id, string $description): RegisteredChannel
    {
        $channel = RegisteredChannel::register(new \DateTimeImmutable(), $name, 1, $description);

        $ref = new \ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, $id);

        return $channel;
    }

    /** @return ArrayObject<int, mixed> */
    private static function recorder(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /** @return ArrayObject<int, string> */
    private static function messages(): \ArrayObject
    {
        return new \ArrayObject();
    }
}
