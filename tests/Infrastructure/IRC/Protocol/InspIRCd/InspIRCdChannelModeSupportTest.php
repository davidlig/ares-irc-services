<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdChannelModeSupport::class)]
final class InspIRCdChannelModeSupportTest extends TestCase
{
    #[Test]
    public function hasVoiceAndOpButNotHalfOpAdminOwner(): void
    {
        $support = new InspIRCdChannelModeSupport();

        self::assertTrue($support->hasVoice());
        self::assertFalse($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertFalse($support->hasAdmin());
        self::assertFalse($support->hasOwner());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertTrue($support->hasPermanentChannelMode());
    }

    #[Test]
    public function getSupportedPrefixModesReturnsVoAndOp(): void
    {
        $support = new InspIRCdChannelModeSupport();

        self::assertSame(['v', 'o'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function getListModeLettersIncludesInspIRCdLists(): void
    {
        $support = new InspIRCdChannelModeSupport();

        self::assertContains('b', $support->getListModeLetters());
        self::assertContains('e', $support->getListModeLetters());
        self::assertContains('I', $support->getListModeLetters());
        self::assertContains('g', $support->getListModeLetters());
    }

    #[Test]
    public function channelSettingUnsetWithParamContainsK(): void
    {
        $support = new InspIRCdChannelModeSupport();

        self::assertSame(['k'], $support->getChannelSettingModesUnsetWithParam());
    }

    #[Test]
    public function getChannelSettingModesUnsetWithoutParamReturnsList(): void
    {
        $support = new InspIRCdChannelModeSupport();

        $modes = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertContains('n', $modes);
        self::assertContains('t', $modes);
    }

    #[Test]
    public function getChannelSettingModesWithParamOnSetReturnsExpectedModes(): void
    {
        $support = new InspIRCdChannelModeSupport();

        $modes = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $modes);
        self::assertContains('l', $modes);
    }
}
