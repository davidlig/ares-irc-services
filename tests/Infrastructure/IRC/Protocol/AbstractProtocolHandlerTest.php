<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol;

use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractProtocolHandler::class)]
final class AbstractProtocolHandlerTest extends TestCase
{
    /**
     * InspIRCdProtocolHandler does not override getSupportedCapabilities(),
     * so calling it exercises AbstractProtocolHandler::getSupportedCapabilities().
     */
    #[Test]
    public function getSupportedCapabilitiesReturnsEmptyByDefault(): void
    {
        $handler = new InspIRCdProtocolHandler();

        self::assertSame([], $handler->getSupportedCapabilities());
    }
}
