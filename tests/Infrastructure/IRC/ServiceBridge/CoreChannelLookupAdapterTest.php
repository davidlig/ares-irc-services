<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ChannelView;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\ServiceBridge\CoreChannelLookupAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(CoreChannelLookupAdapter::class)]
final class CoreChannelLookupAdapterTest extends TestCase
{
    #[Test]
    public function findByChannelNameReturnsNullForInvalidChannelName(): void
    {
        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('findByName');
        $adapter = new CoreChannelLookupAdapter($repo);

        self::assertNull($adapter->findByChannelName('nohash'));
        self::assertNull($adapter->findByChannelName('#'));
    }

    #[Test]
    public function findByChannelNameReturnsNullWhenChannelNotFound(): void
    {
        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->method('findByName')->willReturn(null);
        $adapter = new CoreChannelLookupAdapter($repo);

        self::assertNull($adapter->findByChannelName('#nonexistent'));
    }

    #[Test]
    public function findByChannelNameReturnsChannelViewWhenChannelExists(): void
    {
        $name = new ChannelName('#test');
        $createdAt = new DateTimeImmutable('2020-01-01 12:00:00');
        $channel = new Channel($name, '+nt', $createdAt);
        $channel->syncMember(new Uid('001ABC'), ChannelMemberRole::Op);
        $channel->updateTopic('Hello world');
        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByName')->with(self::callback(static fn (ChannelName $n) => '#test' === $n->value))->willReturn($channel);
        $adapter = new CoreChannelLookupAdapter($repo);

        $view = $adapter->findByChannelName('#test');

        self::assertInstanceOf(ChannelView::class, $view);
        self::assertSame('#test', $view->name);
        self::assertSame('+nt', $view->modes);
        self::assertSame('Hello world', $view->topic);
        self::assertSame(1, $view->memberCount);
        self::assertSame($createdAt->getTimestamp(), $view->timestamp);
        self::assertCount(1, $view->members);
        self::assertSame('001ABC', $view->members[0]['uid']);
        self::assertSame('o', $view->members[0]['roleLetter']);
    }

    #[Test]
    public function findByChannelNameMapsAllMemberRolesToModeLetters(): void
    {
        $name = new ChannelName('#multi');
        $createdAt = new DateTimeImmutable('2020-01-01 12:00:00');
        $channel = new Channel($name, '+nt', $createdAt);
        $channel->syncMember(new Uid('001V'), ChannelMemberRole::Voice);
        $channel->syncMember(new Uid('002H'), ChannelMemberRole::HalfOp);
        $channel->syncMember(new Uid('003O'), ChannelMemberRole::Op);
        $channel->syncMember(new Uid('004A'), ChannelMemberRole::Admin);
        $channel->syncMember(new Uid('005Q'), ChannelMemberRole::Owner);
        $channel->syncMember(new Uid('006N'), ChannelMemberRole::None);
        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->method('findByName')->willReturn($channel);
        $adapter = new CoreChannelLookupAdapter($repo);

        $view = $adapter->findByChannelName('#multi');

        self::assertInstanceOf(ChannelView::class, $view);
        self::assertCount(6, $view->members);
        $letters = array_column($view->members, 'roleLetter');
        self::assertContains('v', $letters);
        self::assertContains('h', $letters);
        self::assertContains('o', $letters);
        self::assertContains('a', $letters);
        self::assertContains('q', $letters);
        self::assertContains('', $letters);
    }
}
