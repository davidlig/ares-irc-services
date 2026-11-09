<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageAccess;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccess;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessAction;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessHandler;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessOutcome;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(ManageChannelAccessHandler::class)]
final class ManageChannelAccessHandlerTest extends TestCase
{
    public function testListResolvesNicknamesInOneBatchAndKeepsMissingNicknameFallback(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('listByChannel')->willReturn([
            new ChannelAccess(1, 12, 300),
            new ChannelAccess(1, 13, 200),
        ]);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountById');
        $accounts->expects(self::once())->method('findNicknamesByIds')->with([12, 13])->willReturn([12 => 'Alice']);
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $events = $this->createStub(ChanServEventPublisher::class);
        $handler = new ManageChannelAccessHandler(
            $this->channelsFor($channel),
            $access,
            $accounts,
            new ChanServAccessHelper($access, $levels),
            $events,
        );

        $result = $handler->handle(new ManageChannelAccess(
            '#test',
            ManageChannelAccessAction::List,
            1,
            true,
            'Founder',
            'ip',
            'host',
            new DateTimeImmutable('2026-01-01'),
        ));

        self::assertSame(ManageChannelAccessOutcome::Listed, $result->outcome);
        self::assertSame([['Alice', 300], ['13', 200]], array_map(
            static fn ($entry): array => [$entry->nickname, $entry->level],
            $result->entries,
        ));
    }

    public function testEmptyListDoesNotResolveNicknames(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('listByChannel')->willReturn([]);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findNicknamesByIds');
        $handler = new ManageChannelAccessHandler(
            $this->channelsFor($channel),
            $access,
            $accounts,
            new ChanServAccessHelper($access, $this->createStub(ChannelLevelRepositoryInterface::class)),
            $this->createStub(ChanServEventPublisher::class),
        );

        $result = $handler->handle(new ManageChannelAccess(
            '#test',
            ManageChannelAccessAction::List,
            1,
            true,
            'Founder',
            'ip',
            'host',
            new DateTimeImmutable('2026-01-01'),
        ));

        self::assertSame(ManageChannelAccessOutcome::ListEmpty, $result->outcome);
        self::assertSame([], $result->entries);
    }

    public function testAddAndDeleteReuseActorAccessLoadedForAuthorization(): void
    {
        foreach ([ManageChannelAccessAction::Add, ManageChannelAccessAction::Delete] as $action) {
            $channel = $this->createStub(RegisteredChannel::class);
            $channel->method('getId')->willReturn(1);
            $channel->method('isFounder')->willReturn(false);
            $actorAccess = new ChannelAccess(1, 10, 400);
            $targetAccess = ManageChannelAccessAction::Delete === $action ? new ChannelAccess(1, 20, 200) : null;
            $access = $this->createMock(ChannelAccessRepositoryInterface::class);
            $access->expects(self::exactly(2))->method('findByChannelAndNick')->willReturnMap([
                [1, 10, $actorAccess],
                [1, 20, $targetAccess],
            ]);
            if (ManageChannelAccessAction::Add === $action) {
                $access->method('countByChannel')->willReturn(0);
                $access->expects(self::once())->method('save');
                $access->expects(self::never())->method('remove');
            } else {
                $access->expects(self::never())->method('save');
                $access->expects(self::once())->method('remove')->with($targetAccess);
            }
            $channels = $this->channelsFor($channel);
            $accounts = $this->createMock(ChanUserAccountPort::class);
            $accounts->expects(self::once())->method('findAccountByNick')->with('Target')->willReturn(new ChanAccountView(20, 'Target', 'en'));
            $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
            $levels->method('findByChannelAndKey')->willReturn(new ChannelLevel(1, ChannelLevel::KEY_ACCESSCHANGE, 300));
            $events = $this->createMock(ChanServEventPublisher::class);
            $events->expects(self::once())->method('publish');
            $handler = new ManageChannelAccessHandler($channels, $access, $accounts, new ChanServAccessHelper($access, $levels), $events);

            $result = $handler->handle(new ManageChannelAccess(
                '#test',
                $action,
                10,
                false,
                'Actor',
                'ip',
                'host',
                new DateTimeImmutable('2026-01-01'),
                'Target',
                ManageChannelAccessAction::Add === $action ? 100 : null,
            ));

            self::assertSame(
                ManageChannelAccessAction::Add === $action ? ManageChannelAccessOutcome::Added : ManageChannelAccessOutcome::Deleted,
                $result->outcome,
            );
        }
    }

    public function testIncompleteActionInputHasNoSideEffects(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('save');
        $levels->expects(self::never())->method('removeAllForChannel');
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('save');
        $access->expects(self::never())->method('remove');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $policy = new ChanServAccessHelper($access, $levels);
        $handler = new ManageChannelAccessHandler($channels, $access, $accounts, $policy, $events);

        foreach ([
            [ManageChannelAccessAction::Add, null, 10],
            [ManageChannelAccessAction::Add, '', 10],
            [ManageChannelAccessAction::Add, 'Target', null],
            [ManageChannelAccessAction::Delete, null, null],
            [ManageChannelAccessAction::Delete, '', null],
        ] as [$action, $target, $level]) {
            $result = $handler->handle(new ManageChannelAccess(
                '#test',
                $action,
                1,
                true,
                'Founder',
                'ip',
                'host',
                new DateTimeImmutable('2026-01-01'),
                targetNickname: $target,
                level: $level,
            ));
            self::assertSame(ManageChannelAccessOutcome::InvalidRequest, $result->outcome);
        }
    }

    private function channelsFor(RegisteredChannel $channel): RegisteredChannelRepositoryInterface
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        return $channels;
    }
}
