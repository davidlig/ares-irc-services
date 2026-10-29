<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\Unban;

use App\ChanServ\Application\Model\ChannelBanSnapshot;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelBanActions;
use App\ChanServ\Application\Port\Out\ChannelBanNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\Unban\UnbanChannel;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelHandler;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelOutcome;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelResult;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnbanChannelHandler::class)]
#[CoversClass(UnbanChannel::class)]
#[CoversClass(UnbanChannelResult::class)]
#[CoversClass(ChannelBanSnapshot::class)]
final class UnbanChannelHandlerTest extends TestCase
{
    /** @return iterable<string, array{int, ?int, bool, bool, bool}> */
    public static function permissions(): iterable
    {
        yield 'default rejects 199' => [199, null, false, false, false];
        yield 'default accepts 200' => [200, null, false, false, true];
        yield 'custom rejects 200' => [200, 300, false, false, false];
        yield 'custom accepts 300' => [300, 300, false, false, true];
        yield 'founder without ACCESS' => [0, 499, true, false, true];
        yield 'existing explicit founder override' => [0, 499, false, true, true];
    }

    #[DataProvider('permissions')]
    public function testAuthorizationBeforeNetworkEffects(int $level, ?int $override, bool $founder, bool $equivalent, bool $allowed): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn($founder);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn($level);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($founder ? null : $access);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects($equivalent ? self::never() : self::once())->method('findByChannelAndKey')->with(10, 'UNBAN')->willReturn(null === $override ? null : new ChannelLevel(10, 'UNBAN', $override));
        $network = $this->createMock(ChannelBanNetworkQuery::class);
        $network->expects($allowed ? self::once() : self::never())->method('findMatchingBans')->with('#test', '001AAA')->willReturn(new ChannelBanSnapshot(true, ['*!*@host', 'Alice!*@*'], 123));
        $actions = $this->createMock(ChannelBanActions::class);
        $actions->expects($allowed ? self::once() : self::never())->method('removeBans')->with('#test', ['*!*@host', 'Alice!*@*'], 123);
        $handler = new UnbanChannelHandler($channels, new ChanServAccessHelper($accessRepo, $levels), $network, $actions);
        if (!$allowed) {
            $this->expectException(InsufficientAccessException::class);
        }
        $result = $handler->handle(new UnbanChannel('#test', 1, '001AAA', $equivalent));
        self::assertSame(UnbanChannelOutcome::Removed, $result->outcome);
        self::assertSame(2, $result->removedCount);
    }

    public function testNonRegisteredChannelHasNoNetworkEffects(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $network = $this->createMock(ChannelBanNetworkQuery::class);
        $network->expects(self::never())->method('findMatchingBans');
        $actions = $this->createMock(ChannelBanActions::class);
        $actions->expects(self::never())->method('removeBans');
        $handler = new UnbanChannelHandler($channels, new ChanServAccessHelper($this->createStub(ChannelAccessRepositoryInterface::class), $this->createStub(ChannelLevelRepositoryInterface::class)), $network, $actions);
        $this->expectException(ChannelNotRegisteredException::class);
        $handler->handle(new UnbanChannel('#test', 1, '001AAA', false));
    }

    public function testEmptyUnavailableAndAbsentChannelNeverMutate(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($this->createStub(RegisteredChannel::class));
        foreach ([
            [null, UnbanChannelOutcome::Unavailable],
            [new ChannelBanSnapshot(false), UnbanChannelOutcome::ChannelNotOnNetwork],
            [new ChannelBanSnapshot(true, [], 123), UnbanChannelOutcome::NoMatches],
        ] as [$snapshot, $outcome]) {
            $network = $this->createStub(ChannelBanNetworkQuery::class);
            $network->method('findMatchingBans')->willReturn($snapshot);
            $actions = $this->createMock(ChannelBanActions::class);
            $actions->expects(self::never())->method('removeBans');
            $handler = new UnbanChannelHandler($channels, new ChanServAccessHelper($this->createStub(ChannelAccessRepositoryInterface::class), $this->createStub(ChannelLevelRepositoryInterface::class)), $network, $actions);
            $result = $handler->handle(new UnbanChannel('#test', 1, '001AAA', true));
            self::assertSame($outcome, $result->outcome);
            self::assertSame(0, $result->removedCount);
        }
    }
}
