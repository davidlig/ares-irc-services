<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\ChannelSweepFailureBudget;
use App\ChanServ\Adapter\In\Maintenance\PurgePendingDeletionChannelsTask;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(PurgePendingDeletionChannelsTask::class)]
#[CoversClass(ChannelSweepFailureBudget::class)]
final class PurgePendingDeletionChannelsTaskTest extends TestCase
{
    /** @var array<string, true> */
    private array $dropped = [];

    #[Test]
    public function metadataReturnsExpectedValues(): void
    {
        $task = new PurgePendingDeletionChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(LoggerInterface::class),
            3600,
            7,
        );

        self::assertSame('chanserv.purge_pending_deletion_channels', $task->getName());
        self::assertSame(3600, $task->getIntervalSeconds());
        self::assertSame(310, $task->getOrder());
    }

    #[Test]
    public function runHardDropsExpiredPendingDeletionChannels(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#old');
        $channel->method('getId')->willReturn(42);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('iteratePendingDeletionBefore')
            ->with(self::callback(static function (DateTimeImmutable $threshold): bool {
                $expected = new DateTimeImmutable()->modify('-7 days');

                return $expected->format('Y-m-d') === $threshold->format('Y-m-d');
            }))
            ->willReturn([$channel]);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with(
            $channel,
            self::isInstanceOf(DateTimeImmutable::class),
            'manual-grace-expired',
            null,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $task = new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7);

        $task->run();
    }

    #[Test]
    public function aFailingDropIsLoggedAndLaterChannelsAreStillDeletedWithTheIdTakenBeforeTheDrop(): void
    {
        $failing = $this->channel(1, '#broken');
        $later = $this->channel(2, '#later');
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('iteratePendingDeletionBefore')->willReturn([$failing, $later]);
        $repo->method('findByIds')->willReturnCallback(static fn (array $ids): array => [1] === $ids ? [$failing] : []);
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willReturnCallback(function (RegisteredChannel $channel): void {
            if ('#broken' === $channel->getName()) {
                throw new RuntimeException('constraint violation');
            }
            $this->dropped[$channel->getName()] = true;
        });
        $errors = self::messages();
        $exceptions = self::recorder();
        $infos = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context) use ($errors, $exceptions): void {
            $errors[] = $message;
            $exceptions[] = $context['exception'] ?? null;
        });
        $logger->method('info')->willReturnCallback(static function (string $message) use ($infos): void {
            $infos[] = $message;
        });

        new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7)->run();

        self::assertCount(1, $errors);
        self::assertStringContainsString('failed to delete pending-deletion channel #broken (id 1); skipping it until the next sweep', $errors->getArrayCopy()[0] ?? '');
        self::assertInstanceOf(RuntimeException::class, $exceptions[0]);
        self::assertSame(['#later' => true], $this->dropped);
        self::assertCount(1, $infos);
        self::assertStringContainsString('permanently deleted channel #later (id 2)', $infos->getArrayCopy()[0] ?? '');
    }

    #[Test]
    public function aPostDropFailureIsReportedAsADeletedChannel(): void
    {
        $channel = $this->channel(5, '#gone');
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('iteratePendingDeletionBefore')->willReturn([$channel]);
        $repo->method('findByIds')->willReturn([]);
        $dropService = $this->createStub(ChanDropService::class);
        $dropService->method('hardDropChannel')->willThrowException(new RuntimeException('listener failed'));
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });

        new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7)->run();

        self::assertCount(1, $errors);
        self::assertStringContainsString('permanently deleted channel #gone (id 5), but a post-drop step failed', $errors->getArrayCopy()[0] ?? '');
    }

    #[Test]
    public function aChannelThatKeepsClosingTheEntityManagerIsSkippedAfterThreeCycles(): void
    {
        $poison = $this->channel(1, '#poison');
        $later = $this->channel(2, '#later');
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('iteratePendingDeletionBefore')->willReturn([$poison, $later]);
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

                throw new RuntimeException('deadlock');
            }
        });
        $errors = self::messages();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $task = new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7, $entityManager);

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

        self::assertSame(['#poison', '#poison', '#poison', '#later'], $attempts->getArrayCopy());
        self::assertStringContainsString('failed to delete pending-deletion channel #poison (id 1); the EntityManager is closed, stopping this batch', $errors->getArrayCopy()[0] ?? '');
        self::assertStringContainsString('closed 3 times in a row; skipping it until the next sweep', $errors->getArrayCopy()[2] ?? '');
    }

    /**
     * Behaves like Doctrine, which unsets the id of a removed entity once the removal is flushed.
     */
    private function channel(int $id, string $name): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn($name);
        $channel->method('getId')->willReturnCallback(function () use ($id, $name): int {
            if (isset($this->dropped[$name])) {
                throw new Error('Typed property must not be accessed before initialization');
            }

            return $id;
        });

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
