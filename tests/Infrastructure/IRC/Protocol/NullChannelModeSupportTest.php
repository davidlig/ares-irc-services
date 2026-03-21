<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol;

use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullChannelModeSupport::class)]
final class NullChannelModeSupportTest extends TestCase
{
    #[Test]
    public function allPrefixCapabilitiesReturnFalse(): void
    {
        $support = new NullChannelModeSupport();

        self::assertFalse($support->hasVoice());
        self::assertFalse($support->hasHalfOp());
        self::assertFalse($support->hasOp());
        self::assertFalse($support->hasAdmin());
        self::assertFalse($support->hasOwner());
        self::assertFalse($support->hasChannelRegisteredMode());
        self::assertFalse($support->hasPermanentChannelMode());
    }

    #[Test]
    public function modeLetterGettersReturnNull(): void
    {
        $support = new NullChannelModeSupport();

        self::assertNull($support->getChannelRegisteredModeLetter());
        self::assertNull($support->getPermanentChannelModeLetter());
    }

    #[Test]
    public function getSupportedPrefixModesReturnsEmpty(): void
    {
        $support = new NullChannelModeSupport();

        self::assertSame([], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function getListModeLettersReturnsDefaultList(): void
    {
        $support = new NullChannelModeSupport();

        self::assertSame(['b', 'e', 'I'], $support->getListModeLetters());
    }

    #[Test]
    public function channelSettingArraysAreEmpty(): void
    {
        $support = new NullChannelModeSupport();

        self::assertSame([], $support->getChannelSettingModesUnsetWithoutParam());
        self::assertSame([], $support->getChannelSettingModesUnsetWithParam());
        self::assertSame([], $support->getChannelSettingModesWithParamOnSet());
    }
}
