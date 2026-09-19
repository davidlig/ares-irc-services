<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Adapter\Out\Persistence\DoctrineChannelRankPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelLevel as PolicyLevel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineChannelRankPolicyRepository::class)]
final class DoctrineChannelRankPolicyRepositoryTest extends TestCase
{
    #[Test]
    public function itNormalizesLookupAndLoadsOnlyTheRankPolicySnapshot(): void
    {
        $channel = $this->channel(7, '#Case', 11, true, true);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#case')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('listByChannel')->with(7)->willReturn([
            new ChannelLevel(7, ChannelLevel::KEY_AUTOOP, 250),
            new ChannelLevel(7, ChannelLevel::KEY_NOJOIN, 10),
        ]);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::once())->method('listByChannel')->with(7)->willReturn([
            new ChannelAccess(7, 21, 300),
            new ChannelAccess(7, 22, 100),
        ]);

        $policy = new DoctrineChannelRankPolicyRepository($channels, $access, $levels, $this->createStub(EntityManagerInterface::class))->findByName('#CASE');

        self::assertNotNull($policy);
        self::assertSame(7, $policy->id);
        self::assertSame('#Case', $policy->name);
        self::assertSame(11, $policy->founderNickId);
        self::assertTrue($policy->secure);
        self::assertTrue($policy->blocked);
        self::assertSame(250, $policy->levels->valueFor(PolicyLevel::AutoOperator));
        self::assertSame(10, $policy->levels->valueFor(PolicyLevel::NoJoin));
        self::assertSame(300, $policy->storedAccessFor(21));
        self::assertSame(100, $policy->storedAccessFor(22));
    }

    #[Test]
    public function itReturnsNullWithoutLoadingRankCollectionsWhenTheChannelIsUnknown(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('listByChannel');
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('listByChannel');

        self::assertNull(new DoctrineChannelRankPolicyRepository($channels, $access, $levels, $this->createStub(EntityManagerInterface::class))->findByName('#missing'));
    }

    #[Test]
    public function itMapsAllRankPolicies(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateAll')->willReturn([
            $this->channel(1, '#one', 10, false, false),
            $this->channel(2, '#two', 20, true, true),
        ]);
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('listByChannel')->willReturn([new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 250)]);
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('listByChannel')->willReturn([new ChannelAccess(1, 21, 300)]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(4))->method('detach');

        $policies = iterator_to_array(new DoctrineChannelRankPolicyRepository($channels, $access, $levels, $em)->all());

        self::assertCount(2, $policies);
        self::assertSame('#one', $policies[0]->name);
        self::assertSame('#two', $policies[1]->name);
    }

    #[Test]
    public function itTouchesAndSavesAnExistingChannel(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('touchLastUsed');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByIds')->with([7])->willReturn([$channel]);
        $channels->expects(self::once())->method('save')->with($channel);

        $this->repository($channels)->touchLastUsed(7);
    }

    #[Test]
    public function itIgnoresTouchForADeletedChannel(): void
    {
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByIds')->with([99])->willReturn([]);
        $channels->expects(self::never())->method('save');

        $this->repository($channels)->touchLastUsed(99);
    }

    private function channel(int $id, string $name, int $founder, bool $secure, bool $blocked): RegisteredChannel
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::never())->method('isMlockActive');
        $channel->expects(self::never())->method('getMlock');
        $channel->expects(self::never())->method('getMlockParam');
        $channel->method('getId')->willReturn($id);
        $channel->method('getName')->willReturn($name);
        $channel->method('getFounderNickId')->willReturn($founder);
        $channel->method('isSecure')->willReturn($secure);
        $channel->method('isBlocked')->willReturn($blocked);

        return $channel;
    }

    private function repository(RegisteredChannelRepositoryInterface $channels): DoctrineChannelRankPolicyRepository
    {
        return new DoctrineChannelRankPolicyRepository(
            $channels,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }
}
