<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Maintenance\PurgePendingDeletionChannelsTask;
use App\Application\ChanServ\Service\ChanDropService;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

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
            ->willReturn([$channel, new stdClass()]);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with($channel, 'manual-grace-expired', null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $task = new PurgePendingDeletionChannelsTask($repo, $dropService, $logger, 3600, 7);

        $task->run();
    }
}
