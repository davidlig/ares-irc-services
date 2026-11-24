<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\ChannelSweepFailureBudget;
use App\ChanServ\Adapter\In\Maintenance\PurgeInactiveChannelsTask;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\ChanServ\Application\Port\Out\ChannelActivityPendingQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PurgeInactiveChannelsTask::class)]
#[CoversClass(ChannelSweepFailureBudget::class)]
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
        $lastUsed = new \DateTimeImmutable('2024-01-01 12:00:00');
        $channel->method('getLastUsedAt')->willReturn($lastUsed);
        $channel->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('iterateRegisteredInactiveSince')
            ->with(self::callback(static function (\DateTimeImmutable $t): bool {
                $expected = new \DateTimeImmutable()->modify('-90 days');

                return $t->format('Y-m-d') === $expected->format('Y-m-d');
            }))
            ->willReturn([$channel]);
        $channelRepo->expects(self::never())->method('delete');

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with(
            $channel,
            self::isInstanceOf(\DateTimeImmutable::class),
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
        $channel->method('getLastUsedAt')->willReturn(new \DateTimeImmutable('-200 days'));
        $channel->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-300 days'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$channel]);

        $writes = 0;
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$writes): int {
            if (0 === $writes++) {
                throw new \RuntimeException('database unavailable');
            }

            return 1;
        });
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('database unavailable'));
        $buffer = new ChannelActivityBuffer($connection);
        $buffer->record(7, new \DateTimeImmutable('-1 minute'));
        $failure = null;
        try {
            $buffer->flush();
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);

        /** @var ArrayObject<int, string> $dropped */
        $dropped = new \ArrayObject();
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

    #[Test]
    public function aChannelWhoseDropFailsOnItsOwnIsLoggedAndLaterChannelsStillExpire(): void
    {
        $failing = $this->inactiveChannel(1, '#broken');
        $later = $this->inactiveChannel(2, '#later');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$failing, $later]);
        $channelRepo->method('findByIds')->willReturn([$failing]);
        $dropped = self::messages();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($dropped): void {
            if ('#broken' === $channel->getName()) {
                throw new \RuntimeException('constraint violation');
            }
            $dropped->append($channel->getName());
        });
        $errors = self::messages();
        $exceptions = self::recorder();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context) use ($errors, $exceptions): void {
            $errors[] = $message;
            $exceptions[] = $context['exception'] ?? null;
        });

        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $logger, 3600, 90, $this->openEntityManager());
        $task->run();

        self::assertSame(['#later'], $dropped->getArrayCopy());
        self::assertCount(1, $errors);
        self::assertStringContainsString('failed to drop inactive channel #broken (id 1); skipping it until the next sweep', $errors->getArrayCopy()[0] ?? '');
        self::assertInstanceOf(\RuntimeException::class, $exceptions[0]);

        $task->run();

        self::assertSame(['#later', '#later'], $dropped->getArrayCopy(), 'a finished sweep retries the failed channel next cycle');
        self::assertCount(2, $errors);
    }

    #[Test]
    public function aPostDropFailureIsReportedAsADroppedChannel(): void
    {
        $channel = $this->inactiveChannel(4, '#gone');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$channel]);
        $channelRepo->method('findByIds')->willReturn([]);
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willThrowException(new \RuntimeException('listener failed'));
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });

        new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $logger, 3600, 90, $this->openEntityManager())->run();

        self::assertCount(1, $errors);
        self::assertStringContainsString('dropped inactive channel #gone (id 4), but a post-drop step failed', $errors->getArrayCopy()[0] ?? '');
    }

    #[Test]
    public function aChannelThatKeepsClosingTheEntityManagerIsSkippedAfterThreeCyclesSoLaterChannelsExpire(): void
    {
        $poison = $this->inactiveChannel(1, '#poison');
        $later = $this->inactiveChannel(2, '#later');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$poison, $later]);
        $open = true;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $attempts = self::messages();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($attempts, &$open): void {
            $attempts->append($channel->getName());
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
        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $logger, 3600, 90, $entityManager, $this->connection(true));

        for ($cycle = 1; $cycle <= 3; ++$cycle) {
            $open = true;
            self::assertInstanceOf(\RuntimeException::class, $this->runCatching($task), 'a closed EntityManager stops the cycle');
        }

        self::assertSame(['#poison', '#poison', '#poison'], $attempts->getArrayCopy());
        self::assertStringContainsString('failed to drop inactive channel #poison (id 1); the EntityManager is closed, stopping this batch', $errors->getArrayCopy()[0] ?? '');
        self::assertStringContainsString('closed 3 times in a row; skipping it until the next sweep', $errors->getArrayCopy()[2] ?? '');

        $open = true;
        $task->run();
        self::assertSame(['#poison', '#poison', '#poison', '#later'], $attempts->getArrayCopy());

        $this->runCatching($task);
        self::assertSame(['#poison', '#poison', '#poison', '#later', '#poison'], $attempts->getArrayCopy(), 'the next sweep retries the skipped channel');
    }

    #[Test]
    public function aChannelThatKeepsClosingTheEntityManagerIsSkippedEvenWhenAnEarlierChannelSucceedsEveryCycle(): void
    {
        $healthy = $this->inactiveChannel(1, '#healthy');
        $poison = $this->inactiveChannel(2, '#poison');
        $later = $this->inactiveChannel(3, '#later');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$healthy, $poison, $later]);
        $open = true;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $attempts = self::messages();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($attempts, &$open): void {
            $attempts->append($channel->getName());
            if ('#poison' === $channel->getName()) {
                $open = false;

                throw new \RuntimeException('deadlock');
            }
        });
        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $this->createStub(LoggerInterface::class), 3600, 90, $entityManager, $this->connection(true));

        for ($cycle = 1; $cycle <= 3; ++$cycle) {
            $open = true;
            self::assertInstanceOf(\RuntimeException::class, $this->runCatching($task));
        }
        $open = true;
        $task->run();

        self::assertSame(
            ['#healthy', '#poison', '#healthy', '#poison', '#healthy', '#poison', '#healthy', '#later'],
            $attempts->getArrayCopy(),
            'an earlier channel that succeeds every cycle does not reset the budget of the failing channel',
        );
    }

    #[Test]
    public function aChannelThatUsedUpItsAttemptsGetsOneAttemptInALaterSweepAndIsThenSkippedAtOnce(): void
    {
        $poison = $this->inactiveChannel(1, '#poison');
        $later = $this->inactiveChannel(2, '#later');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$poison, $later]);
        $open = true;
        $poisonFails = new \ArrayObject([true]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $attempts = self::messages();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($attempts, &$open, $poisonFails): void {
            $attempts->append($channel->getName());
            if (true === $poisonFails[0] && '#poison' === $channel->getName()) {
                $open = false;

                throw new \RuntimeException('deadlock');
            }
        });
        $databaseAnswers = new \ArrayObject([true]);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturnCallback(static function () use ($databaseAnswers): int {
            if (true !== $databaseAnswers[0]) {
                throw new \RuntimeException('server has gone away');
            }

            return 1;
        });
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $logger, 3600, 90, $entityManager, $connection);
        $cycle = function () use ($task, &$open): ?\Throwable {
            $open = true;

            return $this->runCatching($task);
        };

        for ($run = 1; $run <= 3; ++$run) {
            $cycle();
        }
        self::assertNull($cycle(), 'the first sweep ends once the exhausted channel is skipped');
        $attempts->exchangeArray([]);

        $databaseAnswers[0] = false;
        self::assertInstanceOf(\RuntimeException::class, $cycle());
        $databaseAnswers[0] = true;
        self::assertInstanceOf(\RuntimeException::class, $cycle());
        self::assertNull($cycle());
        self::assertSame(['#poison', '#poison', '#later'], $attempts->getArrayCopy(), 'an outage is not counted; a counted failure skips the exhausted channel at once');
        self::assertStringContainsString(
            'failed to drop inactive channel #poison (id 1) again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch',
            $errors->getArrayCopy()[4] ?? '',
        );

        $poisonFails[0] = false;
        $attempts->exchangeArray([]);
        self::assertNull($cycle());
        $poisonFails[0] = true;
        self::assertInstanceOf(\RuntimeException::class, $cycle());
        self::assertInstanceOf(\RuntimeException::class, $cycle());
        self::assertSame(['#poison', '#later', '#poison', '#poison'], $attempts->getArrayCopy(), 'a channel that succeeded gets a full budget again');
    }

    #[Test]
    public function aDatabaseOutageNeverSkipsAChannel(): void
    {
        $channel = $this->inactiveChannel(1, '#healthy');
        $later = $this->inactiveChannel(2, '#later');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('iterateRegisteredInactiveSince')->willReturn([$channel, $later]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $attempts = self::messages();
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(static function (RegisteredChannel $channel) use ($attempts): void {
            $attempts->append($channel->getName());

            throw new \RuntimeException('server has gone away');
        });
        $task = new PurgeInactiveChannelsTask($channelRepo, $dropService, $this->noPendingActivity(), $this->createStub(LoggerInterface::class), 3600, 90, $entityManager, $this->connection(false));

        for ($cycle = 1; $cycle <= 5; ++$cycle) {
            self::assertInstanceOf(\RuntimeException::class, $this->runCatching($task));
        }

        self::assertSame(['#healthy', '#healthy', '#healthy', '#healthy', '#healthy'], $attempts->getArrayCopy());
    }

    private function inactiveChannel(int $id, string $name): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn($id);
        $channel->method('getName')->willReturn($name);
        $channel->method('getLastUsedAt')->willReturn(new \DateTimeImmutable('-200 days'));
        $channel->method('getCreatedAt')->willReturn(new \DateTimeImmutable('-300 days'));

        return $channel;
    }

    private function noPendingActivity(): ChannelActivityPendingQuery
    {
        $pending = $this->createStub(ChannelActivityPendingQuery::class);
        $pending->method('hasPendingActivity')->willReturn(false);

        return $pending;
    }

    private function openEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);

        return $entityManager;
    }

    private function connection(bool $answers): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        if ($answers) {
            $connection->method('fetchOne')->willReturn(1);
        } else {
            $connection->method('fetchOne')->willThrowException(new \RuntimeException('server has gone away'));
        }

        return $connection;
    }

    private function runCatching(PurgeInactiveChannelsTask $task): ?\Throwable
    {
        try {
            $task->run();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
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
