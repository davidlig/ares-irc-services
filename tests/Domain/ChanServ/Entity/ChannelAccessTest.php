<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Entity;

use App\Domain\ChanServ\Entity\ChannelAccess;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelAccess::class)]
final class ChannelAccessTest extends TestCase
{
    #[Test]
    public function constructorAcceptsValidLevel(): void
    {
        $access = new ChannelAccess(1, 10, 100);

        self::assertSame(1, $access->getChannelId());
        self::assertSame(10, $access->getNickId());
        self::assertSame(100, $access->getLevel());
    }

    #[Test]
    public function constructorRejectsLevelBelowMin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access level must be between 1 and 499');

        new ChannelAccess(1, 10, 0);
    }

    #[Test]
    public function constructorAcceptsMinAndMaxLevel(): void
    {
        $min = new ChannelAccess(1, 10, ChannelAccess::LEVEL_MIN);
        self::assertSame(ChannelAccess::LEVEL_MIN, $min->getLevel());

        $max = new ChannelAccess(1, 10, ChannelAccess::LEVEL_MAX);
        self::assertSame(ChannelAccess::LEVEL_MAX, $max->getLevel());
    }

    #[Test]
    public function constructorRejectsLevelAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access level must be between 1 and 499');

        new ChannelAccess(1, 10, 500);
    }

    #[Test]
    public function updateLevelAcceptsValidLevel(): void
    {
        $access = new ChannelAccess(1, 10, 50);
        $access->updateLevel(200);

        self::assertSame(200, $access->getLevel());
    }

    #[Test]
    public function updateLevelRejectsInvalidLevel(): void
    {
        $access = new ChannelAccess(1, 10, 50);

        $this->expectException(InvalidArgumentException::class);

        $access->updateLevel(0);
    }

    #[Test]
    public function updateLevelRejectsLevelAboveMax(): void
    {
        $access = new ChannelAccess(1, 10, 50);
        $this->expectException(InvalidArgumentException::class);
        $access->updateLevel(500);
    }
}
