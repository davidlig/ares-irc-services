<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealIRCdChannelModeSupport::class)]
final class UnrealIRCdChannelModeSupportTest extends TestCase
{
    #[Test]
    public function allPrefixCapabilitiesReturnTrue(): void
    {
        $support = new UnrealIRCdChannelModeSupport();

        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertTrue($support->hasPermanentChannelMode());
    }

    #[Test]
    public function getSupportedPrefixModesReturnsUnrealOrder(): void
    {
        $support = new UnrealIRCdChannelModeSupport();

        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function getListModeLettersReturnsBanExemptInvex(): void
    {
        $support = new UnrealIRCdChannelModeSupport();

        self::assertSame(['b', 'e', 'I'], $support->getListModeLetters());
    }

    #[Test]
    public function channelSettingModesContainExpectedLetters(): void
    {
        $support = new UnrealIRCdChannelModeSupport();

        self::assertContains('k', $support->getChannelSettingModesUnsetWithParam());
        self::assertContains('L', $support->getChannelSettingModesUnsetWithParam());
        self::assertContains('n', $support->getChannelSettingModesUnsetWithoutParam());
        self::assertContains('t', $support->getChannelSettingModesUnsetWithoutParam());
    }

    #[Test]
    public function getChannelSettingModesWithParamOnSetReturnsExpectedModes(): void
    {
        $support = new UnrealIRCdChannelModeSupport();

        $modes = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $modes);
        self::assertContains('l', $modes);
        self::assertContains('L', $modes);
    }
}
