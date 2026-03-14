<?php

declare(strict_types=1);

namespace App\Tests\Domain\MemoServ\Entity;

use App\Domain\MemoServ\Entity\MemoSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoSettings::class)]
final class MemoSettingsTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $settings = new MemoSettings(10, null, false);

        self::assertSame(10, $settings->getTargetNickId());
        self::assertNull($settings->getTargetChannelId());
        self::assertFalse($settings->isEnabled());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $settings = new MemoSettings(null, 20, true);

        self::assertNull($settings->getTargetNickId());
        self::assertSame(20, $settings->getTargetChannelId());
        self::assertTrue($settings->isEnabled());
    }

    #[Test]
    public function constructorThrowsWhenBothSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoSettings(10, 20, true);
    }

    #[Test]
    public function constructorThrowsWhenNeitherSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoSettings(null, null, true);
    }

    #[Test]
    public function setIdAndGetIdUsedByDoctrine(): void
    {
        $settings = new MemoSettings(10, null, true);
        $settings->setId(7);

        self::assertSame(7, $settings->getId());
    }

    #[Test]
    public function setEnabledUpdatesValue(): void
    {
        $settings = new MemoSettings(10, null, false);
        $settings->setEnabled(true);

        self::assertTrue($settings->isEnabled());
    }
}
