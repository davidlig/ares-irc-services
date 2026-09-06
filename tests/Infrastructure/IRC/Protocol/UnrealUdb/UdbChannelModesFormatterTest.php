<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbChannelModesFormatter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbChannelModeSupport;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbChannelModesFormatter::class)]
final class UdbChannelModesFormatterTest extends TestCase
{
    private UdbChannelModesFormatter $formatter;

    private UnrealUdbChannelModeSupport $udbSupport;

    protected function setUp(): void
    {
        $this->formatter = new UdbChannelModesFormatter();
        $this->udbSupport = new UnrealUdbChannelModeSupport();
    }

    #[Test]
    public function formatReturnsNullForEmptyModeString(): void
    {
        self::assertNull($this->formatter->format('', [], $this->udbSupport));
    }

    #[Test]
    public function formatReturnsNullWhenOnlyExcludedModesArePresent(): void
    {
        // +r, +P, +q, +o, +b are excluded
        self::assertNull($this->formatter->format('+rP', [], $this->udbSupport));
        self::assertNull($this->formatter->format('+qo', [], $this->udbSupport));
        self::assertNull($this->formatter->format('+b', [], $this->udbSupport));
    }

    #[Test]
    public function formatFormatsSimpleModesWithoutParams(): void
    {
        $formatted = $this->formatter->format('+nt', [], $this->udbSupport);
        self::assertSame('+nt', $formatted);
    }

    #[Test]
    public function formatExcludesPlusRAndPlusPWhileKeepingOtherModes(): void
    {
        $formatted = $this->formatter->format('+rPnt', [], $this->udbSupport);
        self::assertSame('+nt', $formatted);
    }

    #[Test]
    public function formatAppendsSingleParameterInOrder(): void
    {
        $formatted = $this->formatter->format('+ntk', ['k' => 'mypassword'], $this->udbSupport);
        self::assertSame('+ntk mypassword', $formatted);
    }

    #[Test]
    public function formatAppendsMultipleParametersInOrderOfModeLetters(): void
    {
        // k then l
        $formattedKl = $this->formatter->format('+ntkl', ['k' => 'mypassword', 'l' => '50'], $this->udbSupport);
        self::assertSame('+ntkl mypassword 50', $formattedKl);

        // l then k
        $formattedLk = $this->formatter->format('+ntlk', ['k' => 'mypassword', 'l' => '50'], $this->udbSupport);
        self::assertSame('+ntlk 50 mypassword', $formattedLk);
    }

    #[Test]
    public function formatDeduplicatesModeLetters(): void
    {
        $formatted = $this->formatter->format('+nttkk', ['k' => 'secret'], $this->udbSupport);
        self::assertSame('+ntk secret', $formatted);
    }

    #[Test]
    public function formatWorksWithUnrealIRCdAndInspIRCdSupport(): void
    {
        $unrealSupport = new UnrealStandaloneChannelModeSupport();
        $formattedUnreal = $this->formatter->format('+rPntk', ['k' => 'key'], $unrealSupport);
        self::assertSame('+ntk key', $formattedUnreal);

        $inspSupport = new InspIRCdChannelModeSupport(
            prefixModes: ['v', 'h', 'o', 'a', 'q'],
            listModeLetters: ['b', 'e', 'I'],
            channelSettingUnsetWithoutParam: ['c', 'i', 'm', 'n', 'p', 's', 't'],
            channelSettingUnsetWithParam: ['k'],
            channelSettingWithParamOnSet: ['k', 'l'],
            hasHalfOp: true,
            hasAdmin: true,
            hasOwner: true,
            hasPermanentMode: true,
            permanentModeLetter: 'P',
            hasRegisteredMode: true,
            registeredModeLetter: 'r',
        );
        $formattedInsp = $this->formatter->format('+rPntl', ['l' => '100'], $inspSupport);
        self::assertSame('+ntl 100', $formattedInsp);
    }
}
