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
}
