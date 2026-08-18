<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbChannelModeSupport::class)]
final class UnrealUdbChannelModeSupportTest extends TestCase
{
    #[Test]
    public function allPrefixCapabilitiesReturnTrue(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertTrue($support->hasPermanentChannelMode());
    }

    #[Test]
    public function modeLetterGettersReturnExpectedValues(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        self::assertSame('r', $support->getChannelRegisteredModeLetter());
        self::assertSame('P', $support->getPermanentChannelModeLetter());
    }

    #[Test]
    public function getSupportedPrefixModesReturnsUnrealOrder(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function getListModeLettersReturnsBanExemptInvex(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        self::assertSame(['b', 'e', 'I'], $support->getListModeLetters());
    }

    #[Test]
    public function channelSettingModesContainExpectedLetters(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        self::assertContains('k', $support->getChannelSettingModesUnsetWithParam());
        self::assertContains('L', $support->getChannelSettingModesUnsetWithParam());
        self::assertContains('n', $support->getChannelSettingModesUnsetWithoutParam());
        self::assertContains('t', $support->getChannelSettingModesUnsetWithoutParam());
    }

    #[Test]
    public function getChannelSettingModesWithParamOnSetReturnsExpectedModes(): void
    {
        $support = new UnrealUdbChannelModeSupport();

        $modes = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $modes);
        self::assertContains('l', $modes);
        self::assertContains('L', $modes);
    }
}
