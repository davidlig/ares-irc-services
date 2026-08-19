<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbChannelSyncSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbChannelSyncSubscriber::class)]
final class UdbChannelSyncSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = UdbChannelSyncSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(ChannelRegisteredEvent::class, $events);
        $this->assertArrayHasKey(ChannelDropEvent::class, $events);
    }

    public function testOnChannelDropNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'Dropped'));
    }

    public function testOnChannelDropSuccess(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL C::#chan');

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'Dropped'));
    }

    public function testOnChannelRegisteredNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
    }

    public function testOnChannelRegisteredNoChannel(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $chanRepo->method('findByChannelName')->willReturn(null);

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
    }

    public function testOnChannelRegisteredNoFounder(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(2);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $nickRepo->method('findById')->willReturn(null);

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
    }

    public function testOnChannelRegisteredSuccess(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS C::#chan::founder fndr');

        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(2);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getNickname')->willReturn('fndr');
        $nickRepo->method('findById')->willReturn($nick);

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
    }

    public function testOnSyncRequestedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '123'));
    }

    public function testOnSyncRequestedWrongBlock(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '123'));
    }

    public function testOnSyncRequestedSuccess(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->exactly(3))->method('writeLine')
            ->willReturnCallback(function (string $line): void {
                static $step = 0;
                $expected = [
                    'DB * DRP C',
                    'DB * INS C::#chan::founder fndr',
                    'DB * FDR C',
                ];
                $this->assertSame($expected[$step++], $line);
            });

        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(2);
        $chan->method('getName')->willReturn('#chan');
        $chanRepo->method('listAll')->willReturn([$chan]);

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getNickname')->willReturn('fndr');
        $nickRepo->method('findById')->willReturn($nick);

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '123'));
    }

    public function testOnSyncRequestedMissingFounder(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->exactly(2))->method('writeLine')
            ->willReturnCallback(function (string $line): void {
                static $step = 0;
                $expected = [
                    'DB * DRP C',
                    'DB * FDR C',
                ];
                $this->assertSame($expected[$step++], $line);
            });

        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(2);
        $chan->method('getName')->willReturn('#chan');
        $chanRepo->method('listAll')->willReturn([$chan]);

        $nickRepo->method('findById')->willReturn(null);

        $sub = new UdbChannelSyncSubscriber($holder, $chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '123'));
    }
}
