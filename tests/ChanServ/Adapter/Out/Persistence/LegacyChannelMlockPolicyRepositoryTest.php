<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Adapter\Out\Persistence\LegacyChannelMlockPolicyRepository;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyChannelMlockPolicyRepository::class)]
final class LegacyChannelMlockPolicyRepositoryTest extends TestCase
{
    #[Test]
    public function itNormalizesLookupAndLoadsOnlyTheMlockPolicySnapshot(): void
    {
        $channel = $this->channel(7, '#Case', true, true, '+kR', ['k' => 'Secret']);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#case')->willReturn($channel);

        $policy = new LegacyChannelMlockPolicyRepository($channels)->findByName('#CASE');

        self::assertNotNull($policy);
        self::assertSame('#Case', $policy->name);
        self::assertTrue($policy->blocked);
        self::assertTrue($policy->modeLock->active);
        self::assertSame(
            [['k', 'Secret'], ['R', null]],
            array_map(
                static fn ($setting): array => [$setting->mode->value, $setting->parameter],
                $policy->modeLock->settings,
            ),
        );
    }

    #[Test]
    public function itReturnsNullWhenTheChannelIsUnknown(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);

        self::assertNull(new LegacyChannelMlockPolicyRepository($channels)->findByName('#missing'));
    }

    #[Test]
    public function itMapsAllChannelsAndKeepsInactiveAndActiveEmptyLocksDistinct(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('listAll')->willReturn([
            $this->channel(1, '#inactive', false, false, '', []),
            $this->channel(2, '#empty', false, true, '', []),
        ]);

        $policies = new LegacyChannelMlockPolicyRepository($channels)->all();

        self::assertCount(2, $policies);
        self::assertFalse($policies[0]->modeLock->active);
        self::assertSame([], $policies[0]->modeLock->settings);
        self::assertTrue($policies[1]->modeLock->active);
        self::assertSame([], $policies[1]->modeLock->settings);
    }

    /** @param array<string, string> $mlockParams */
    private function channel(
        int $id,
        string $name,
        bool $blocked,
        bool $mlockActive,
        string $mlock,
        array $mlockParams,
    ): RegisteredChannel {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::never())->method('getId');
        $channel->expects(self::never())->method('getFounderNickId');
        $channel->expects(self::never())->method('isSecure');
        $channel->method('getName')->willReturn($name);
        $channel->method('isBlocked')->willReturn($blocked);
        $channel->method('isMlockActive')->willReturn($mlockActive);
        $channel->method('getMlock')->willReturn($mlock);
        $channel->method('getMlockParam')->willReturnCallback(
            static fn (string $letter): ?string => $mlockParams[$letter] ?? null,
        );

        return $channel;
    }
}
