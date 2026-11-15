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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Throwable;

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
        $channel1->suspend('Abuse', new DateTimeImmutable('-1 hour'));

        $channel2 = $this->createChannelWithId('#expired2', 2, 'Expired 2');
        $channel2->suspend('Spam', new DateTimeImmutable('-2 hours'));

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
        $failing->suspend('Abuse', new DateTimeImmutable('-1 hour'));
        $later = $this->createChannelWithId('#later', 2, 'Later');
        $later->suspend('Spam', new DateTimeImmutable('-1 hour'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateExpiredSuspensions')->willReturn([$failing, $later]);
        $channelRepo->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            if ('#broken' === $channel->getName()) {
                throw new RuntimeException('constraint violation');
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
        self::assertInstanceOf(RuntimeException::class, $exceptions[0]);
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
            $poison->suspend('Abuse', new DateTimeImmutable('-1 hour'));
            $later = $this->createChannelWithId('#later', 2, 'Later');
            $later->suspend('Spam', new DateTimeImmutable('-1 hour'));

            return [$poison, $later];
        });
        $channelRepo->method('save')->willReturnCallback(static function (RegisteredChannel $channel) use ($saved, &$open): void {
            $saved[] = $channel->getName();
            if ('#poison' === $channel->getName()) {
                $open = false;

                throw new RuntimeException('deadlock');
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
            } catch (Throwable $e) {
                $failure = $e;
            }
            self::assertInstanceOf(RuntimeException::class, $failure);
        }
        $open = true;
        $task->run();

        self::assertSame(['#poison', '#poison', '#poison', '#later'], $saved->getArrayCopy());
        self::assertStringContainsString('failed to auto-unsuspend channel #poison (id 1); the EntityManager is closed, stopping this batch', $errors->getArrayCopy()[0] ?? '');
        self::assertStringContainsString('closed 3 times in a row; skipping it until the next sweep', $errors->getArrayCopy()[2] ?? '');
    }

    private function createChannelWithId(string $name, int $id, string $description): RegisteredChannel
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), $name, 1, $description);

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, $id);

        return $channel;
    }

    /** @return ArrayObject<int, mixed> */
    private static function recorder(): ArrayObject
    {
        return new ArrayObject();
    }

    /** @return ArrayObject<int, string> */
    private static function messages(): ArrayObject
    {
        return new ArrayObject();
    }
}
