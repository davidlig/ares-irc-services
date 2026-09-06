<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealStandaloneUserModeSupport::class)]
final class UnrealStandaloneUserModeSupportTest extends TestCase
{
    #[Test]
    public function getIrcOpUserModesReturnsExpectedModes(): void
    {
        $support = new UnrealStandaloneUserModeSupport();

        $modes = $support->getIrcOpUserModes();

        self::assertSame(['H', 'o', 'q', 's', 'W'], $modes);
    }

    #[Test]
    public function buildModeParamsReturnsModeStringWithoutParams(): void
    {
        $support = new UnrealStandaloneUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['H', 'W']);

        self::assertSame('+HW', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsRemoveAlsoReturnsEmptyParams(): void
    {
        $support = new UnrealStandaloneUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('-', ['s', 'W']);

        self::assertSame('-sW', $modeStr);
        self::assertSame([], $params);
    }
}
