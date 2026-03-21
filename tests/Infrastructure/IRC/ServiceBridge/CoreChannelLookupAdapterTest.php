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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $repo->expects(self::atLeastOnce())->method('findByName')->willReturn(null);
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
        $repo->expects(self::atLeastOnce())->method('findByName')->willReturn($channel);
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

    #[Test]
    public function listAllReturnsEmptyArrayWhenNoChannels(): void
    {
        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('all')->willReturn([]);
        $adapter = new CoreChannelLookupAdapter($repo);

        self::assertSame([], $adapter->listAll());
    }

    #[Test]
    public function listAllReturnsAllChannelsAsViews(): void
    {
        $createdAt1 = new DateTimeImmutable('2020-01-01 12:00:00');
        $createdAt2 = new DateTimeImmutable('2020-01-02 12:00:00');
        $channel1 = new Channel(new ChannelName('#test'), '+nt', $createdAt1);
        $channel1->syncMember(new Uid('001ABC'), ChannelMemberRole::Op);
        $channel2 = new Channel(new ChannelName('#other'), '+n', $createdAt2);

        $repo = $this->createMock(ChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('all')->willReturn([$channel1, $channel2]);
        $adapter = new CoreChannelLookupAdapter($repo);

        $views = $adapter->listAll();

        self::assertCount(2, $views);
        self::assertSame('#test', $views[0]->name);
        self::assertSame('+nt', $views[0]->modes);
        self::assertSame(1, $views[0]->memberCount);
        self::assertSame('#other', $views[1]->name);
        self::assertSame('+n', $views[1]->modes);
        self::assertSame(0, $views[1]->memberCount);
    }
}
