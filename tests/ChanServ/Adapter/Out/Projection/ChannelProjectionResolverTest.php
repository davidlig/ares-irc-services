<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Projection;

use App\ChanServ\Adapter\Out\Projection\ChannelProjectionResolver;
use App\ChanServ\Application\Port\In\ChannelAccessProjection;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ChannelProjectionResolver::class)]
#[CoversClass(ChannelProjection::class)]
#[CoversClass(ChannelAccessProjection::class)]
final class ChannelProjectionResolverTest extends TestCase
{
    #[Test]
    public function projectsChannelsAndAccessEntriesWithoutExposingDomainEntities(): void
    {
        $channel = RegisteredChannel::register('#Ares', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 11);
        $channel->updateTopic('Welcome');
        $channel->configureMlock(true, '+ntkl', ['k' => 'secret', 'l' => '10']);
        $channel->configureTopicLock(true);
        $entry = new ChannelAccess(11, 9, 300);

        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('listAll')->willReturn([$channel]);
        $channels->method('findByChannelName')->willReturn($channel);
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('listByChannel')->willReturn([$entry]);
        $resolver = new ChannelProjectionResolver($channels, $access);

        $expected = new ChannelProjection(
            11,
            '#Ares',
            7,
            'Welcome',
            true,
            '+ntkl',
            ['k' => 'secret', 'l' => '10'],
            true,
            false,
            null,
            false,
            false,
            [new ChannelAccessProjection(9, 300)],
        );
        $all = $resolver->all();
        self::assertCount(1, $all);
        self::assertProjection($expected, $all[0]);

        $found = $resolver->findByName('#ares');
        self::assertNotNull($found);
        self::assertProjection($expected, $found);
    }

    #[Test]
    public function returnsNullWhenTheChannelDoesNotExist(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);

        self::assertNull(new ChannelProjectionResolver(
            $channels,
            $this->createStub(ChannelAccessRepositoryInterface::class),
        )->findByName('#missing'));
    }

    private static function assertProjection(ChannelProjection $expected, ChannelProjection $actual): void
    {
        self::assertSame($expected->id, $actual->id);
        self::assertSame($expected->name, $actual->name);
        self::assertSame($expected->founderNickId, $actual->founderNickId);
        self::assertSame($expected->topic, $actual->topic);
        self::assertSame($expected->mlockActive, $actual->mlockActive);
        self::assertSame($expected->mlock, $actual->mlock);
        self::assertSame($expected->mlockParams, $actual->mlockParams);
        self::assertSame($expected->topicLock, $actual->topicLock);
        self::assertSame($expected->forbidden, $actual->forbidden);
        self::assertSame($expected->forbiddenReason, $actual->forbiddenReason);
        self::assertSame($expected->suspended, $actual->suspended);
        self::assertSame($expected->pendingDeletion, $actual->pendingDeletion);
        self::assertCount(1, $actual->access);
        self::assertSame($expected->access[0]->nickId, $actual->access[0]->nickId);
        self::assertSame($expected->access[0]->level, $actual->access[0]->level);
    }
}
