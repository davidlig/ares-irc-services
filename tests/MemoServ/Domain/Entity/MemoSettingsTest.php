<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Domain\Entity;

use App\MemoServ\Domain\Entity\MemoSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MemoSettings::class)]
final class MemoSettingsTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $settings = new MemoSettings(10, null, true);

        self::assertSame(10, $settings->getTargetNickId());
        self::assertNull($settings->getTargetChannelId());
        self::assertTrue($settings->isEnabled());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $settings = new MemoSettings(null, 5, false);

        self::assertNull($settings->getTargetNickId());
        self::assertSame(5, $settings->getTargetChannelId());
        self::assertFalse($settings->isEnabled());
    }

    #[Test]
    public function constructorThrowsWhenBothTargetsSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoSettings(10, 5, true);
    }

    #[Test]
    public function constructorThrowsWhenNeitherTargetSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoSettings(null, null, true);
    }

    #[Test]
    public function enableAndDisableToggleState(): void
    {
        $settings = new MemoSettings(1, null, true);
        self::assertTrue($settings->isEnabled());

        $settings->disable();
        self::assertFalse($settings->isEnabled());

        $settings->enable();
        self::assertTrue($settings->isEnabled());
    }

    #[Test]
    public function getIdReturnsReflectedId(): void
    {
        $settings = new MemoSettings(1, null, true);
        $prop = new ReflectionProperty(MemoSettings::class, 'id');
        $prop->setValue($settings, 77);

        self::assertSame(77, $settings->getId());
    }
}
