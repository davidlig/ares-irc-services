<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdChannelModeSupport::class)]
final class InspIRCdChannelModeSupportTest extends TestCase
{
    private InspIRCdChannelModeSupportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new InspIRCdChannelModeSupportFactory();
    }

    #[Test]
    public function defaultSupportHasAllRanks(): void
    {
        $support = $this->factory->createDefault();

        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertTrue($support->hasPermanentChannelMode());
    }

    #[Test]
    public function defaultModeLetterGettersReturnExpectedValues(): void
    {
        $support = $this->factory->createDefault();

        self::assertSame('r', $support->getChannelRegisteredModeLetter());
        self::assertSame('P', $support->getPermanentChannelModeLetter());
    }

    #[Test]
    public function defaultGetSupportedPrefixModesReturnsAllRanks(): void
    {
        $support = $this->factory->createDefault();

        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function defaultGetListModeLettersIncludesInspIRCdLists(): void
    {
        $support = $this->factory->createDefault();

        self::assertContains('b', $support->getListModeLetters());
        self::assertContains('e', $support->getListModeLetters());
        self::assertContains('I', $support->getListModeLetters());
        self::assertContains('g', $support->getListModeLetters());
    }

    #[Test]
    public function defaultChannelSettingUnsetWithParamContainsK(): void
    {
        $support = $this->factory->createDefault();

        self::assertSame(['k'], $support->getChannelSettingModesUnsetWithParam());
    }

    #[Test]
    public function defaultGetChannelSettingModesUnsetWithoutParamReturnsList(): void
    {
        $support = $this->factory->createDefault();

        $modes = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertContains('n', $modes);
        self::assertContains('t', $modes);
        self::assertContains('P', $modes);
    }

    #[Test]
    public function defaultGetChannelSettingModesWithParamOnSetReturnsExpectedModes(): void
    {
        $support = $this->factory->createDefault();

        $modes = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $modes);
        self::assertContains('l', $modes);
    }

    #[Test]
    public function minimalSupportHasOnlyVoiceAndOp(): void
    {
        $support = new InspIRCdChannelModeSupport(
            prefixModes: ['v', 'o'],
            listModeLetters: ['b', 'e', 'I'],
            channelSettingUnsetWithoutParam: ['i', 'm', 'n', 't', 'r'],
            channelSettingUnsetWithParam: ['k'],
            channelSettingWithParamOnSet: ['k', 'l'],
            hasHalfOp: false,
            hasAdmin: false,
            hasOwner: false,
            hasPermanentMode: false,
            permanentModeLetter: null,
            hasRegisteredMode: true,
            registeredModeLetter: 'r',
        );

        self::assertTrue($support->hasVoice());
        self::assertFalse($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertFalse($support->hasAdmin());
        self::assertFalse($support->hasOwner());
        self::assertFalse($support->hasPermanentChannelMode());
        self::assertNull($support->getPermanentChannelModeLetter());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertSame('r', $support->getChannelRegisteredModeLetter());
        self::assertSame(['v', 'o'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function supportWithNoRegisteredMode(): void
    {
        $support = new InspIRCdChannelModeSupport(
            prefixModes: ['v', 'o'],
            listModeLetters: ['b'],
            channelSettingUnsetWithoutParam: ['i', 't'],
            channelSettingUnsetWithParam: [],
            channelSettingWithParamOnSet: [],
            hasHalfOp: false,
            hasAdmin: false,
            hasOwner: false,
            hasPermanentMode: false,
            permanentModeLetter: null,
            hasRegisteredMode: false,
            registeredModeLetter: null,
        );

        self::assertFalse($support->hasChannelRegisteredMode());
        self::assertNull($support->getChannelRegisteredModeLetter());
    }

    #[Test]
    public function hasVoiceReturnsFalseWhenNoVoiceInPrefixModes(): void
    {
        $support = new InspIRCdChannelModeSupport(
            prefixModes: ['o'],
            listModeLetters: ['b'],
            channelSettingUnsetWithoutParam: ['i'],
            channelSettingUnsetWithParam: [],
            channelSettingWithParamOnSet: [],
            hasHalfOp: false,
            hasAdmin: false,
            hasOwner: false,
            hasPermanentMode: false,
            permanentModeLetter: null,
            hasRegisteredMode: false,
            registeredModeLetter: null,
        );

        self::assertFalse($support->hasVoice());
        self::assertTrue($support->hasOp());
    }
}
