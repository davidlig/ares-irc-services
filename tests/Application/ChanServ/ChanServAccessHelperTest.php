<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\Port\ChannelModeSupportInterface;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServAccessHelper::class)]
final class ChanServAccessHelperTest extends TestCase
{
    #[Test]
    public function getLevelValueReturnsStoredValueWhenPresent(): void
    {
        $level = new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 250);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($level);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame(250, $helper->getLevelValue(1, ChannelLevel::KEY_AUTOOP));
    }

    #[Test]
    public function getLevelValueReturnsDefaultWhenAbsent(): void
    {
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame(ChannelLevel::getDefault(ChannelLevel::KEY_AUTOOP), $helper->getLevelValue(1, ChannelLevel::KEY_AUTOOP));
    }

    #[Test]
    public function effectiveAccessLevelReturnsFounderLevelForFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame(ChannelAccess::FOUNDER_LEVEL, $helper->effectiveAccessLevel($channel, 10));
    }

    #[Test]
    public function effectiveAccessLevelReturnsAccessLevelWhenNotFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(400);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame(400, $helper->effectiveAccessLevel($channel, 10));
    }

    #[Test]
    public function effectiveAccessLevelReturnsZeroWhenNotFounderAndNoAccess(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame(0, $helper->effectiveAccessLevel($channel, 99));
    }

    #[Test]
    public function requireLevelThrowsWhenLevelInsufficient(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 300));

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $this->expectException(InsufficientAccessException::class);

        $helper->requireLevel($channel, 5, ChannelLevel::KEY_AUTOOP, '#test', 'OP');
    }

    #[Test]
    public function requireLevelDoesNotThrowWhenLevelSufficient(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(400);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 300));

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $helper->requireLevel($channel, 10, ChannelLevel::KEY_AUTOOP, '#test', 'OP');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function canManageLevelReturnsTrueWhenManagerLevelHigher(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(400);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertTrue($helper->canManageLevel($channel, 10, 300));
    }

    #[Test]
    public function canManageLevelReturnsFalseWhenManagerLevelNotStrictlyHigher(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertFalse($helper->canManageLevel($channel, 10, 300));
        self::assertFalse($helper->canManageLevel($channel, 10, 400));
    }

    #[Test]
    public function getDesiredPrefixLetterReturnsFirstSupportedForFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isFounder')->willReturn(true);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['v', 'o', 'q']);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame('q', $helper->getDesiredPrefixLetter($channel, 1, $modeSupport));
    }

    #[Test]
    public function getDesiredPrefixLetterReturnsEmptyWhenFounderButNoSupportedPrefix(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isFounder')->willReturn(true);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame('', $helper->getDesiredPrefixLetter($channel, 1, $modeSupport));
    }

    #[Test]
    public function getDesiredPrefixLetterReturnsOpForNonFounderWhenLevelMeetsAutoOp(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(250);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturnCallback(
            static fn (int $c, string $key): ?ChannelLevel => match ($key) {
                ChannelLevel::KEY_AUTOADMIN => new ChannelLevel(1, $key, 400),
                ChannelLevel::KEY_AUTOOP => new ChannelLevel(1, $key, 200),
                default => new ChannelLevel(1, $key, 0),
            }
        );
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getSupportedPrefixModes')->willReturn(['v', 'h', 'o', 'a', 'q']);

        $helper = new ChanServAccessHelper($accessRepo, $levelRepo);

        self::assertSame('o', $helper->getDesiredPrefixLetter($channel, 10, $modeSupport));
    }
}
