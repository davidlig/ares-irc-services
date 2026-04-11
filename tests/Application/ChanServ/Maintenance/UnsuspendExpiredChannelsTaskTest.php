<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Maintenance\UnsuspendExpiredChannelsTask;
use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

#[CoversClass(UnsuspendExpiredChannelsTask::class)]
final class UnsuspendExpiredChannelsTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsExpectedValue(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelSuspensionService::class),
            new NullLogger(),
            3600,
        );

        self::assertSame('chanserv.unsuspend_expired_channels', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelSuspensionService::class),
            new NullLogger(),
            7200,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns196(): void
    {
        $task = new UnsuspendExpiredChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelSuspensionService::class),
            new NullLogger(),
            3600,
        );

        self::assertSame(196, $task->getOrder());
    }

    #[Test]
    public function runUnsuspendsExpiredChannels(): void
    {
        $channel1 = $this->createChannelWithId('#expired1', 1, 'Expired 1');
        $channel1->suspend('Abuse', new DateTimeImmutable('-1 hour'));

        $channel2 = $this->createChannelWithId('#expired2', 2, 'Expired 2');
        $channel2->suspend('Spam', new DateTimeImmutable('-2 hours'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('findExpiredSuspensions')
            ->willReturn([$channel1, $channel2]);
        $channelRepo->expects(self::exactly(2))->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::exactly(2))->method('liftSuspension');

        $task = new UnsuspendExpiredChannelsTask(
            $channelRepo,
            $suspensionService,
            new NullLogger(),
            3600,
        );
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredSuspensions(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('findExpiredSuspensions')
            ->willReturn([]);
        $channelRepo->expects(self::never())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::never())->method('liftSuspension');

        $task = new UnsuspendExpiredChannelsTask(
            $channelRepo,
            $suspensionService,
            new NullLogger(),
            3600,
        );
        $task->run();
    }

    private function createChannelWithId(string $name, int $id, string $description): RegisteredChannel
    {
        $channel = RegisteredChannel::register($name, 1, $description);

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, $id);

        return $channel;
    }
}
