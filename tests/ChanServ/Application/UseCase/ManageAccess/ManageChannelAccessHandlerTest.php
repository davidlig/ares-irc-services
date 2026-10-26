<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageAccess;

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
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageChannelAccessHandler::class)]
final class ManageChannelAccessHandlerTest extends TestCase
{
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
}
