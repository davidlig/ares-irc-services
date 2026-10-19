<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\UnbanUser;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUser;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserHandler;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserResult;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnbanUser::class)]
#[CoversClass(UnbanUserHandler::class)]
#[CoversClass(UnbanUserResult::class)]
final class UnbanUserHandlerTest extends TestCase
{
    #[Test]
    public function founderEquivalentSkipsTheLevelCheckAndReturnsTheChannelTimestamp(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('@1700000000'));
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepository->expects(self::never())->method('findByChannelAndNick');
        $levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepository->expects(self::never())->method('findByChannelAndKey');

        $handler = new UnbanUserHandler($channels, new ChanServAccessHelper($accessRepository, $levelRepository));
        $result = $handler->handle(new UnbanUser('#Test', 2, true));

        self::assertSame(1700000000, $result->channelCreationTimestamp);
    }

    #[Test]
    public function accessAtTheUnbanLevelIsAccepted(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('@1700000100'));
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepository->method('findByChannelAndKey')->willReturn(null);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(100);
        $accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepository->method('findByChannelAndNick')->willReturn($access);

        $handler = new UnbanUserHandler($channels, new ChanServAccessHelper($accessRepository, $levelRepository));
        $result = $handler->handle(new UnbanUser('#test', 2, false));

        self::assertSame(1700000100, $result->channelCreationTimestamp);
    }

    #[Test]
    public function throwsWhenTheChannelIsNotRegistered(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);
        $accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);

        $handler = new UnbanUserHandler($channels, new ChanServAccessHelper($accessRepository, $levelRepository));

        $this->expectException(ChannelNotRegisteredException::class);
        $handler->handle(new UnbanUser('#missing', 2, false));
    }

    #[Test]
    public function throwsWhenTheAccessLevelIsBelowUnban(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepository->method('findByChannelAndKey')->willReturn(null);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(99);
        $accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepository->method('findByChannelAndNick')->willReturn($access);

        $handler = new UnbanUserHandler($channels, new ChanServAccessHelper($accessRepository, $levelRepository));

        $this->expectException(InsufficientAccessException::class);
        $handler->handle(new UnbanUser('#test', 2, false));
    }
}
