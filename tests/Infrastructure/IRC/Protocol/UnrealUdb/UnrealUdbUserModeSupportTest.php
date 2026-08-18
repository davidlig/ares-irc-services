<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbUserModeSupport::class)]
final class UnrealUdbUserModeSupportTest extends TestCase
{
    #[Test]
    public function getIrcOpUserModesReturnsExpectedModes(): void
    {
        $support = new UnrealUdbUserModeSupport();

        $modes = $support->getIrcOpUserModes();

        self::assertSame(['H', 'o', 'q', 's', 'W'], $modes);
    }

    #[Test]
    public function buildModeParamsReturnsModeStringWithoutParams(): void
    {
        $support = new UnrealUdbUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['H', 'W']);

        self::assertSame('+HW', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsRemoveAlsoReturnsEmptyParams(): void
    {
        $support = new UnrealUdbUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('-', ['s', 'W']);

        self::assertSame('-sW', $modeStr);
        self::assertSame([], $params);
    }
}
