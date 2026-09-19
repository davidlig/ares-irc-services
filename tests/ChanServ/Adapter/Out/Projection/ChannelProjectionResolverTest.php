<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Projection;

use App\ChanServ\Adapter\Out\Projection\ChannelProjectionResolver;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ChannelProjectionResolver::class)]
#[CoversClass(ChannelProjection::class)]
final class ChannelProjectionResolverTest extends TestCase
{
    #[Test]
    public function projectsChannelsWithoutExposingDomainEntities(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Ares', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 11);
        $channel->updateTopic('Welcome', new DateTimeImmutable('2026-01-01 00:00:00'));
        $channel->configureMlock(true, '+ntkl', ['k' => 'secret', 'l' => '10']);
        $channel->configureTopicLock(true);

        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateAll')->willReturn([$channel]);
        $channels->method('findByChannelName')->willReturn($channel);
        $resolver = new ChannelProjectionResolver($channels);

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

        self::assertNull(new ChannelProjectionResolver($channels)->findByName('#missing'));
    }

    #[Test]
    public function projectsSuspensionReasonOnlyForSuspendedChannels(): void
    {
        $suspended = RegisteredChannel::register(new DateTimeImmutable(), '#Suspended', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($suspended, 21);
        $suspended->suspend('abuse');

        $active = RegisteredChannel::register(new DateTimeImmutable(), '#Active', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($active, 22);
        $active->suspend('abuse');
        $active->unsuspend();

        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnCallback(
            static fn (string $name): RegisteredChannel => '#suspended' === $name ? $suspended : $active,
        );

        $resolver = new ChannelProjectionResolver($channels);

        self::assertSame('abuse', $resolver->findByName('#suspended')?->suspensionReason);
        self::assertNull($resolver->findByName('#active')?->suspensionReason);
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
        self::assertSame($expected->suspensionReason, $actual->suspensionReason);
    }
}
