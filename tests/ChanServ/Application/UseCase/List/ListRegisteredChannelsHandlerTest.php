<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\List;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\List\ListedRegisteredChannel;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannels;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandler;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListRegisteredChannels::class)]
#[CoversClass(ListRegisteredChannelsHandler::class)]
#[CoversClass(ListRegisteredChannelsResult::class)]
#[CoversClass(ListedRegisteredChannel::class)]
final class ListRegisteredChannelsHandlerTest extends TestCase
{
    #[Test]
    public function returnsFilteredPageWithFounderNamesAndAllChannelStatuses(): void
    {
        $registeredAt = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $lastUsedAt = new DateTimeImmutable('2026-02-03T04:05:00+00:00');
        $active = RegisteredChannel::register($registeredAt, '#david', 10, 'Active');
        $suspended = RegisteredChannel::register($registeredAt, '#davidlig', 20, 'Suspended');
        $suspended->suspend('Suspended');
        $pendingDeletion = RegisteredChannel::register($registeredAt, '#davinia', 30, 'Pending');
        $pendingDeletion->markPendingDeletion($registeredAt);
        $forbidden = RegisteredChannel::createForbidden($registeredAt, '#davi', 'Forbidden');
        foreach ([$active, $suspended, $pendingDeletion, $forbidden] as $channel) {
            $channel->touchLastUsed($lastUsedAt);
        }

        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*avi*')->willReturn(4);
        $repository->expects(self::once())
            ->method('searchByPattern')
            ->with('*avi*', 0, 4)
            ->willReturn([$active, $suspended, $pendingDeletion, $forbidden]);

        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())
            ->method('findNicknamesByIds')
            ->with([10, 20, 30])
            ->willReturn([10 => 'David', 20 => 'Davinia']);

        $result = new ListRegisteredChannelsHandler($repository, $accounts, 4)
            ->handle(new ListRegisteredChannels('*avi*'));

        self::assertSame('*avi*', $result->pattern);
        self::assertSame(1, $result->page);
        self::assertSame(4, $result->pageSize);
        self::assertSame(4, $result->total);
        self::assertSame([
            ['#david', 'David', $registeredAt, $lastUsedAt, ChannelStatus::Active],
            ['#davidlig', 'Davinia', $registeredAt, $lastUsedAt, ChannelStatus::Suspended],
            ['#davinia', null, $registeredAt, $lastUsedAt, ChannelStatus::PendingDeletion],
            ['#davi', null, $registeredAt, $lastUsedAt, ChannelStatus::Forbidden],
        ], array_map(
            static fn (ListedRegisteredChannel $entry): array => [
                $entry->channelName,
                $entry->founderName,
                $entry->registeredAt,
                $entry->lastUsedAt,
                $entry->status,
            ],
            $result->entries,
        ));
    }

    #[Test]
    public function normalizesPageAndRequestsOnlyThatBoundedPage(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#chan', 42, 'Channel');
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('#*')->willReturn(5);
        $repository->expects(self::once())
            ->method('searchByPattern')
            ->with('#*', 2, 2)
            ->willReturn([$channel]);

        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findNicknamesByIds')->with([42])->willReturn([42 => 'Founder']);

        $result = new ListRegisteredChannelsHandler($repository, $accounts, 2)
            ->handle(new ListRegisteredChannels('#*', 2));

        self::assertSame(2, $result->page);
        self::assertSame('Founder', $result->entries[0]->founderName);
    }

    #[Test]
    public function returnsOutOfRangePageWithoutFetchingRowsOrFounders(): void
    {
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('countByPattern')->with('*')->willReturn(2);
        $repository->expects(self::never())->method('searchByPattern');

        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findNicknamesByIds');

        $result = new ListRegisteredChannelsHandler($repository, $accounts, 2)
            ->handle(new ListRegisteredChannels('*', 2));

        self::assertSame(2, $result->page);
        self::assertSame(2, $result->total);
        self::assertSame([], $result->entries);
    }
}
