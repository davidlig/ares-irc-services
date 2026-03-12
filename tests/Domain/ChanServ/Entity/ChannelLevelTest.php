<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Entity;

use App\Domain\ChanServ\Entity\ChannelLevel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelLevel::class)]
final class ChannelLevelTest extends TestCase
{
    #[Test]
    public function constructorAcceptsValidValue(): void
    {
        $level = new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 300);

        self::assertSame(1, $level->getChannelId());
        self::assertSame(ChannelLevel::KEY_AUTOOP, $level->getLevelKey());
        self::assertSame(300, $level->getValue());
    }

    #[Test]
    public function constructorAcceptsMinAndMaxValue(): void
    {
        $min = new ChannelLevel(1, ChannelLevel::KEY_AUTOVOICE, ChannelLevel::LEVEL_MIN);
        $max = new ChannelLevel(1, ChannelLevel::KEY_ACCESSLIST, ChannelLevel::LEVEL_MAX);

        self::assertSame(ChannelLevel::LEVEL_MIN, $min->getValue());
        self::assertSame(ChannelLevel::LEVEL_MAX, $max->getValue());
    }

    #[Test]
    public function constructorRejectsValueBelowMin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level value must be between');

        new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, -2);
    }

    #[Test]
    public function constructorRejectsValueAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level value must be between');

        new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 500);
    }

    #[Test]
    public function updateLevelValueAcceptsValidValue(): void
    {
        $level = new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 100);
        $level->updateLevelValue(200);

        self::assertSame(200, $level->getValue());
    }

    #[Test]
    public function updateLevelValueRejectsInvalid(): void
    {
        $level = new ChannelLevel(1, ChannelLevel::KEY_AUTOOP, 100);

        $this->expectException(InvalidArgumentException::class);

        $level->updateLevelValue(500);
    }

    #[Test]
    public function getDefaultReturnsKnownKey(): void
    {
        self::assertSame(300, ChannelLevel::getDefault(ChannelLevel::KEY_AUTOOP));
        self::assertSame(400, ChannelLevel::getDefault(ChannelLevel::KEY_ACCESSLIST));
    }

    #[Test]
    public function getDefaultReturnsZeroForUnknownKey(): void
    {
        self::assertSame(0, ChannelLevel::getDefault('UNKNOWN'));
    }
}
