<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\UdbChannelModesFormatter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbChannelModeSupport;
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
    public function formatUsesOnlyTheUnrealUdbModeContract(): void
    {
        self::assertSame('+ntk key', $this->formatter->format('+rPntk', ['k' => 'key'], $this->udbSupport));
    }
}
