<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Domain\Entity\NickHistory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickHistoryService::class)]
final class NickHistoryServiceTest extends TestCase
{
    #[Test]
    public function recordActionCreatesAndSavesHistory(): void
    {
        $savedHistory = null;
        $repo = $this->createMock(NickHistoryRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (NickHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $service = new NickHistoryService($repo);
        $fixedTime = new DateTimeImmutable('2024-01-15 10:30:00');

        $history = $service->recordAction(
            nickId: 123,
            action: 'SUSPEND',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            message: 'history.message.suspended',
            extraData: ['duration' => '7d'],
            performedAt: $fixedTime,
        );

        self::assertSame(123, $history->getNickId());
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
        $repo = $this->createMock(NickHistoryRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $service = new NickHistoryService($repo);

        $history = $service->recordAction(
            nickId: 123,
            action: 'SET_PASSWORD',
            performedBy: 'User',
            performedByNickId: null,
            performedByIp: '10.0.0.1',
            performedByHost: 'user@host',
            message: 'history.message.password_changed',
            performedAt: new DateTimeImmutable('2024-01-15 10:30:00'),
            extraData: [],
        );

        self::assertNull($history->getPerformedByNickId());
    }

    #[Test]
    public function recordActionMergesIpAndHostIntoExtraData(): void
    {
        $repo = $this->createMock(NickHistoryRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $service = new NickHistoryService($repo);

        $history = $service->recordAction(
            nickId: 1,
            action: 'SET_EMAIL',
            performedBy: 'User',
            performedByNickId: 1,
            performedByIp: '2001:db8::1',
            performedByHost: 'user@ipv6.example',
            message: 'history.message.email_changed',
            performedAt: new DateTimeImmutable('2024-01-15 10:30:00'),
            extraData: ['old_email' => 'old@example.com', 'new_email' => 'new@example.com'],
        );

        self::assertSame('2001:db8::1', $history->getExtraData()['ip']);
        self::assertSame('user@ipv6.example', $history->getExtraData()['host']);
        self::assertSame('old@example.com', $history->getExtraData()['old_email']);
        self::assertSame('new@example.com', $history->getExtraData()['new_email']);
    }

    #[Test]
    public function recordActionUsesProvidedTime(): void
    {
        $repo = $this->createStub(NickHistoryRepositoryInterface::class);
        $service = new NickHistoryService($repo);

        $performedAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $history = $service->recordAction(
            nickId: 1,
            action: 'TEST',
            performedBy: 'User',
            performedByNickId: null,
            performedByIp: '127.0.0.1',
            performedByHost: 'user@localhost',
            message: 'Test message',
            extraData: [],
            performedAt: $performedAt,
        );

        self::assertSame($performedAt, $history->getPerformedAt());
    }
}
