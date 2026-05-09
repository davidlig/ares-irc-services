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
    public function getIrcOpUserModesReturnsEmptyArray(): void
    {
        $support = new InspIRCdUserModeSupport();

        $modes = $support->getIrcOpUserModes();

        self::assertSame([], $modes);
    }

    #[Test]
    public function buildModeParamsAdd(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', ['x', 'y']);

        self::assertSame('+xy', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsRemove(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('-', ['x', 'y']);

        self::assertSame('-xy', $modeStr);
        self::assertSame([], $params);
    }

    #[Test]
    public function buildModeParamsEmptyModes(): void
    {
        $support = new InspIRCdUserModeSupport();

        [$modeStr, $params] = $support->buildModeParams('+', []);

        self::assertSame('+', $modeStr);
        self::assertSame([], $params);
    }
}
