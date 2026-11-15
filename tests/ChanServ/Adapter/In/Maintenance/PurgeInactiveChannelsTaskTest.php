<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\PurgeInactiveChannelsTask;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\ChanServ\Application\Port\Out\ChannelActivityPendingQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PurgeInactiveChannelsTask::class)]
final class PurgeInactiveChannelsTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(ChannelActivityPendingQuery::class),
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );

        self::assertSame('chanserv.purge_inactive_channels', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsInjectedValue(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(ChannelActivityPendingQuery::class),
            $this->createStub(LoggerInterface::class),
            7200,
            60,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns300(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(ChannelActivityPendingQuery::class),
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );

        self::assertSame(300, $task->getOrder());
    }

    #[Test]
    public function itRequiresTheChannelActivityFlushSoStoredLastUseIsCurrent(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(ChannelActivityPendingQuery::class),
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );

        self::assertSame(['chanserv.flush_channel_activity'], $task->getPrerequisiteTaskNames());
    }

    #[Test]
    public function runDoesNothingWhenInactivityExpiryDaysIsZero(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::never())->method('iterateRegisteredInactiveSince');
        $channelRepo->expects(self::never())->method('delete');
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('hardDropChannel');

        $task = new PurgeInactiveChannelsTask(
            $channelRepo,
            $dropService,
            $this->createStub(ChannelActivityPendingQuery::class),
            $this->createStub(LoggerInterface::class),
            3600,
            0,
        );
        $task->run();
    }

    #[Test]
    public function runHardDropsAndLogsEachInactiveChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $lastUsed = new DateTimeImmutable('2024-01-01 12:00:00');
        $channel->method('getLastUsedAt')->willReturn($lastUsed);
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('2023-01-01'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('iterateRegisteredInactiveSince')
            ->with(self::callback(static function (DateTimeImmutable $t): bool {
                $expected = new DateTimeImmutable()->modify('-90 days');

                return $t->format('Y-m-d') === $expected->format('Y-m-d');
            }))
            ->willReturn([$channel]);
        $channelRepo->expects(self::never())->method('delete');

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with(
            $channel,
            self::isInstanceOf(DateTimeImmutable::class),
            'inactivity',
        );

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $task = new PurgeInactiveChannelsTask(
            $channelRepo,
            $dropService,
            $this->createStub(ChannelActivityPendingQuery::class),
            $logger,
            3600,
            90,
        );
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('deleted channel #test', $logMessages[0]);
        self::assertStringContainsString('inactivity', $logMessages[0]);
    }

    #[Test]
    public function runKeepsAChannelWithUnflushedActivityAndDropsItOnceTheActivityIsPersisted(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(7);
        $channel->method('getName')->willReturn('#busy');
        $channel->method('getLastUsedAt')->willReturn(new DateTimeImmutable('-200 days'));
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('-300 days'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$channel]);

        $writes = 0;
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$writes): int {
            if (0 === $writes++) {
                throw new RuntimeException('database unavailable');
            }

            return 1;
        });
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new DateTimeImmutable('-1 minute'));
        $failure = null;
        try {
            $buffer->flush();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);

        /** @var ArrayObject<int, string> $dropped */
        $dropped = new ArrayObject();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $dropping) use ($dropped): void {
            $dropped->append($dropping->getName());
        });
        $infos = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$infos): void {
            $infos[] = $message;
        });

        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $buffer, $logger, 3600, 90);
        $task->run();

        self::assertSame([], $dropped->getArrayCopy());
        self::assertCount(1, $infos);
        self::assertStringContainsString('kept channel #busy (id 7)', $infos[0]);
        self::assertStringContainsString('unflushed activity', $infos[0]);

        $buffer->flush();
        $task->run();

        self::assertSame(['#busy'], $dropped->getArrayCopy());
    }
}
