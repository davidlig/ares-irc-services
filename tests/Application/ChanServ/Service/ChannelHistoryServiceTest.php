<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Service\ChannelHistoryService;
use App\Domain\ChanServ\Entity\ChannelHistory;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelHistoryService::class)]
final class ChannelHistoryServiceTest extends TestCase
{
    #[Test]
    public function recordActionCreatesAndSavesHistory(): void
    {
        $savedHistory = null;
        $repo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $service = new ChannelHistoryService($repo);
        $fixedTime = new DateTimeImmutable('2024-01-15 10:30:00');

        $history = $service->recordAction(
            channelId: 123,
            action: 'SUSPEND',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            message: 'history.message.suspended',
            extraData: ['duration' => '7d'],
            performedAt: $fixedTime,
        );

        self::assertSame(123, $history->getChannelId());
        self::assertSame('SUSPEND', $history->getAction());
        self::assertSame('OperNick', $history->getPerformedBy());
        self::assertSame(456, $history->getPerformedByNickId());
        self::assertSame($fixedTime, $history->getPerformedAt());
        self::assertSame('history.message.suspended', $history->getMessage());
        self::assertSame([
            'ip' => '192.168.1.100',
            'host' => 'oper@example.com',
            'duration' => '7d',
        ], $history->getExtraData());

        self::assertNotNull($savedHistory);
        self::assertSame($history, $savedHistory);
    }

    #[Test]
    public function recordActionWithNullNickId(): void
    {
        $repo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $service = new ChannelHistoryService($repo);

        $history = $service->recordAction(
            channelId: 123,
            action: 'SET_FOUNDER',
            performedBy: 'User',
            performedByNickId: null,
            performedByIp: '10.0.0.1',
            performedByHost: 'user@host',
            message: 'history.message.founder_changed',
            extraData: [],
        );

        self::assertNull($history->getPerformedByNickId());
    }

    #[Test]
    public function recordActionMergesIpAndHostIntoExtraData(): void
    {
        $repo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $service = new ChannelHistoryService($repo);

        $history = $service->recordAction(
            channelId: 1,
            action: 'ACCESS_ADD',
            performedBy: 'User',
            performedByNickId: 1,
            performedByIp: '2001:db8::1',
            performedByHost: 'user@ipv6.example',
            message: 'history.message.access_add',
            extraData: ['target_nickname' => 'Target', 'level' => '100'],
        );

        self::assertSame('2001:db8::1', $history->getExtraData()['ip']);
        self::assertSame('user@ipv6.example', $history->getExtraData()['host']);
        self::assertSame('Target', $history->getExtraData()['target_nickname']);
        self::assertSame('100', $history->getExtraData()['level']);
    }

    #[Test]
    public function recordActionUsesCurrentTimeWhenNullProvided(): void
    {
        $repo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $service = new ChannelHistoryService($repo);

        $before = new DateTimeImmutable();
        $history = $service->recordAction(
            channelId: 1,
            action: 'TEST',
            performedBy: 'User',
            performedByNickId: null,
            performedByIp: '127.0.0.1',
            performedByHost: 'user@localhost',
            message: 'Test message',
            extraData: [],
            performedAt: null,
        );
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $history->getPerformedAt());
        self::assertLessThanOrEqual($after, $history->getPerformedAt());
    }
}
