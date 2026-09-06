<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineNickAssociatedChannelsAdapter;
use App\NickServ\Application\Port\Out\AssociatedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineNickAssociatedChannelsAdapter::class)]
#[CoversClass(AssociatedChannel::class)]
final class DoctrineNickAssociatedChannelsAdapterTest extends TestCase
{
    #[Test]
    public function returnsEmptyArrayWhenNoChannelsOrAccessEntriesFound(): void
    {
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByNick')->with(123)->willReturn([]);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByFounderNickId')->with(123)->willReturn([]);
        $channelRepo->expects(self::once())->method('findBySuccessorNickId')->with(123)->willReturn([]);

        $adapter = new DoctrineNickAssociatedChannelsAdapter($accessRepo, $channelRepo);

        self::assertSame([], $adapter->findChannelsForNick(123));
    }

    #[Test]
    public function resolvesFounderSuccessorAndAccessChannelsWithPrecedence(): void
    {
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);

        // Founder channels: id 1 (#founder), id 2 (#founder-and-successor)
        $founder1 = $this->createStub(RegisteredChannel::class);
        $founder1->method('getId')->willReturn(1);
        $founder1->method('getName')->willReturn('#founder');

        $founder2 = $this->createStub(RegisteredChannel::class);
        $founder2->method('getId')->willReturn(2);
        $founder2->method('getName')->willReturn('#founder-and-successor');

        // Successor channels: id 2 (duplicate of founder, founder takes precedence), id 3 (#successor)
        $successor2 = $this->createStub(RegisteredChannel::class);
        $successor2->method('getId')->willReturn(2);
        $successor2->method('getName')->willReturn('#founder-and-successor');

        $successor3 = $this->createStub(RegisteredChannel::class);
        $successor3->method('getId')->willReturn(3);
        $successor3->method('getName')->willReturn('#successor');

        // Access entries: id 1 (duplicate of founder), id 4 (#access-channel), id 5 (missing channel entity)
        $access1 = $this->createStub(ChannelAccess::class);
        $access1->method('getChannelId')->willReturn(1);
        $access1->method('getLevel')->willReturn(100);

        $access4 = $this->createStub(ChannelAccess::class);
        $access4->method('getChannelId')->willReturn(4);
        $access4->method('getLevel')->willReturn(200);

        $access5 = $this->createStub(ChannelAccess::class);
        $access5->method('getChannelId')->willReturn(5);
        $access5->method('getLevel')->willReturn(300);

        $channel4 = $this->createStub(RegisteredChannel::class);
        $channel4->method('getId')->willReturn(4);
        $channel4->method('getName')->willReturn('#access-channel');

        $accessRepo->expects(self::once())->method('findByNick')->with(42)->willReturn([$access1, $access4, $access5]);
        $channelRepo->expects(self::once())->method('findByFounderNickId')->with(42)->willReturn([$founder1, $founder2]);
        $channelRepo->expects(self::once())->method('findBySuccessorNickId')->with(42)->willReturn([$successor2, $successor3]);
        $channelRepo->expects(self::once())->method('findByIds')->with([4, 5])->willReturn([$channel4]);

        $adapter = new DoctrineNickAssociatedChannelsAdapter($accessRepo, $channelRepo);
        $results = $adapter->findChannelsForNick(42);

        self::assertCount(5, $results);

        self::assertSame('#founder', $results[0]->name);
        self::assertSame('founder', $results[0]->type);
        self::assertNull($results[0]->level);

        self::assertSame('#founder-and-successor', $results[1]->name);
        self::assertSame('founder', $results[1]->type);
        self::assertNull($results[1]->level);

        self::assertSame('#successor', $results[2]->name);
        self::assertSame('successor', $results[2]->type);
        self::assertNull($results[2]->level);

        self::assertSame('#access-channel', $results[3]->name);
        self::assertSame('access', $results[3]->type);
        self::assertSame(200, $results[3]->level);

        self::assertSame('', $results[4]->name);
        self::assertSame('access', $results[4]->type);
        self::assertSame(300, $results[4]->level);
    }
}
