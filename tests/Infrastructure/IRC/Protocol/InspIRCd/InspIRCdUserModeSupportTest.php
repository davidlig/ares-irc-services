<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdUserModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdUserModeSupport::class)]
final class InspIRCdUserModeSupportTest extends TestCase
{
    #[Test]
    public function getIrcOpUserModesReturnsExpectedModes(): void
    {
        $support = new InspIRCdUserModeSupport();

        $modes = $support->getIrcOpUserModes();

        self::assertSame(['s', 'W'], $modes);
    }

    #[Test]
    public function buildModeParamsAddWithSIncludesSnomask(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['s', 'W']);

        self::assertSame('+sW', $modeStr);
        self::assertSame(['+*'], $params);
    }

    #[Test]
    public function buildModeParamsAddWithoutSDoesNotIncludeParam(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['W']);

        self::assertSame('+W', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsRemoveDoesNotIncludeParam(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('-', ['s', 'W']);

        self::assertSame('-sW', $modeStr);
        self::assertSame([], $params);
    }
}
