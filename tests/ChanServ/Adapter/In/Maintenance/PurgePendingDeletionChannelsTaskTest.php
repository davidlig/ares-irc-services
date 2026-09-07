<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\PurgePendingDeletionChannelsTask;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PurgePendingDeletionChannelsTask::class)]
final class PurgePendingDeletionChannelsTaskTest extends TestCase
{
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
            ->method('findPendingDeletionBefore')
            ->with(self::callback(static function (DateTimeImmutable $threshold): bool {
                $expected = new DateTimeImmutable()->modify('-7 days');

                return $expected->format('Y-m-d') === $threshold->format('Y-m-d');
            }))
            ->willReturn([$channel]);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with($channel, 'manual-grace-expired', null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $task = new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7);

        $task->run();
    }
}
