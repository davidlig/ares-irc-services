<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ShowRegistrationStatus;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\RegistrationStatusView;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShowRegistrationStatusHandler::class)]
#[CoversClass(RegistrationStatusView::class)]
final class ShowRegistrationStatusHandlerTest extends TestCase
{
    #[Test]
    public function returnsNullForMissingAndMapsEveryLifecycleState(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $pending = RegisteredChannel::createPendingRegistration($at, '#pending', 7, 'description');
        $rejected = RegisteredChannel::createPendingRegistration($at, '#rejected', 8, 'description');
        $rejected->rejectRegistration('Reason', 9, 'Reviewer', $at, $at->modify('+24 hours'));
        $active = RegisteredChannel::register($at, '#active', 10, 'description');
        $suspended = RegisteredChannel::register($at, '#suspended', 11, 'description');
        $suspended->suspend('reason');
        $forbidden = RegisteredChannel::createForbidden($at, '#forbidden', 'reason');
        $drop = RegisteredChannel::register($at, '#drop', 12, 'description');
        $drop->markPendingDeletion($at);
        $channelsByName = ['#pending' => $pending, '#rejected' => $rejected, '#active' => $active, '#suspended' => $suspended, '#forbidden' => $forbidden, '#drop' => $drop];
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnCallback(static function (string $name) use ($channelsByName): ?RegisteredChannel {
            return $channelsByName[$name] ?? null;
        });
        $handler = new ShowRegistrationStatusHandler($channels);

        self::assertNull($handler->handle('#missing'));
        foreach (['#pending' => ChannelStatus::Pending, '#rejected' => ChannelStatus::Rejected, '#active' => ChannelStatus::Active, '#suspended' => ChannelStatus::Suspended, '#forbidden' => ChannelStatus::Forbidden, '#drop' => ChannelStatus::PendingDeletion] as $name => $status) {
            self::assertSame($status, $handler->handle($name)?->status);
        }
        $rejectedView = $handler->handle('#rejected');
        self::assertNotNull($rejectedView);
        self::assertSame('Reason', $rejectedView->rejectionReason);
        self::assertSame('Reviewer', $rejectedView->rejectedByNickname);
        self::assertSame(8, $rejectedView->requesterAccountId);
        self::assertSame($at->format(DATE_ATOM), $rejectedView->rejectedAt?->format(DATE_ATOM));
        self::assertSame($at->modify('+24 hours')->format(DATE_ATOM), $rejectedView->restrictedUntil?->format(DATE_ATOM));
        $activeView = $handler->handle('#active');
        self::assertNotNull($activeView);
        self::assertSame(10, $activeView->requesterAccountId);
        self::assertNull($activeView->rejectionReason);
        self::assertNull($activeView->rejectedByNickname);
        self::assertNull($activeView->rejectedAt);
        self::assertNull($activeView->restrictedUntil);
    }
}
