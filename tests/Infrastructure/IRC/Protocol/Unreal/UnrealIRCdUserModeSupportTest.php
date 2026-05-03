<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealIRCdUserModeSupport::class)]
final class UnrealIRCdUserModeSupportTest extends TestCase
{
    #[Test]
    public function getIrcOpUserModesReturnsExpectedModes(): void
    {
        $support = new UnrealIRCdUserModeSupport();

        $modes = $support->getIrcOpUserModes();

        self::assertSame(['H', 'o', 'q', 's', 'W'], $modes);
    }

    #[Test]
    public function buildModeParamsReturnsModeStringWithoutParams(): void
    {
        $support = new UnrealIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['H', 'W']);

        self::assertSame('+HW', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsRemoveAlsoReturnsEmptyParams(): void
    {
        $support = new UnrealIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('-', ['s', 'W']);

        self::assertSame('-sW', $modeStr);
        self::assertSame([], $params);
    }
}
