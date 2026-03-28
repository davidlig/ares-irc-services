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
}
