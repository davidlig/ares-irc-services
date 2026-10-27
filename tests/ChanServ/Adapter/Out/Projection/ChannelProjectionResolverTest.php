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

use function array_map;

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
        $channel->configureSecure(true);
        $channel->configureIrcopOnly(true);

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
            null,
            true,
            true,
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

    #[Test]
    public function omitsPendingAndRejectedChannelsFromAllAndNameProjections(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#pending', 7, 'request');
        $rejected = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#rejected', 8, 'request');
        $rejected->rejectRegistration('No topic', 9, 'Reviewer', new DateTimeImmutable(), null);
        $active = RegisteredChannel::register(new DateTimeImmutable(), '#active', 10, 'registered');
        $suspended = RegisteredChannel::register(new DateTimeImmutable(), '#suspended', 11, 'registered');
        $suspended->suspend('reason');
        $forbidden = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden', 'reason');
        $pendingDeletion = RegisteredChannel::register(new DateTimeImmutable(), '#drop', 12, 'registered');
        $pendingDeletion->markPendingDeletion(new DateTimeImmutable());
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($pending, 31);
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($rejected, 32);
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($active, 33);
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($suspended, 34);
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($forbidden, 35);
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($pendingDeletion, 36);

        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateAll')->willReturn([$pending, $rejected, $active, $suspended, $forbidden, $pendingDeletion]);
        $channels->method('findByChannelName')->willReturnCallback(static fn (string $name): RegisteredChannel => match ($name) {
            '#pending' => $pending,
            '#rejected' => $rejected,
            '#suspended' => $suspended,
            '#forbidden' => $forbidden,
            '#drop' => $pendingDeletion,
            default => $active,
        });
        $resolver = new ChannelProjectionResolver($channels);

        self::assertSame(['#active', '#suspended', '#forbidden', '#drop'], array_map(static fn (ChannelProjection $projection): string => $projection->name, $resolver->all()));
        self::assertNull($resolver->findByName('#pending'));
        self::assertNull($resolver->findByName('#rejected'));
        self::assertSame('#active', $resolver->findByName('#active')?->name);
        self::assertSame('#suspended', $resolver->findByName('#suspended')?->name);
        self::assertSame('#forbidden', $resolver->findByName('#forbidden')?->name);
        self::assertSame('#drop', $resolver->findByName('#drop')?->name);
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
        self::assertSame($expected->secure, $actual->secure);
        self::assertSame($expected->ircopOnly, $actual->ircopOnly);
    }
}
