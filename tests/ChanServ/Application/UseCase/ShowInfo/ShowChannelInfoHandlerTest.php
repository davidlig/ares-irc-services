<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ShowInfo;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfo;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfoHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShowChannelInfoHandler::class)]
final class ShowChannelInfoHandlerTest extends TestCase
{
    #[Test]
    public function pendingRegistrationIsNotShownAsRegisteredInfo(): void
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#pending', 7, 'private detail');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#pending')->willReturn($channel);
        $handler = new ShowChannelInfoHandler(
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );

        self::assertNull($handler->handle(new ShowChannelInfo('#pending')));
    }

    #[Test]
    public function rejectedRegistrationIsNotShownAsRegisteredInfo(): void
    {
        $at = new DateTimeImmutable();
        $channel = RegisteredChannel::createPendingRegistration($at, '#rejected', 7, 'private detail');
        $channel->rejectRegistration('Reason', 9, 'OperNick', $at, null);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#rejected')->willReturn($channel);
        $handler = new ShowChannelInfoHandler(
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );

        self::assertNull($handler->handle(new ShowChannelInfo('#rejected')));
    }

    #[Test]
    public function returnsChannelInfoViewWithVerboseState(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $handler = new ShowChannelInfoHandler(
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );

        $view = $handler->handle(new ShowChannelInfo('#test'));
        self::assertNotNull($view);
        self::assertTrue($view->verbose);

        $channel->configureVerbose(false);
        $channels2 = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels2->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $handler2 = new ShowChannelInfoHandler(
            $channels2,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );
        $view2 = $handler2->handle(new ShowChannelInfo('#test'));
        self::assertNotNull($view2);
        self::assertFalse($view2->verbose);
    }
}
