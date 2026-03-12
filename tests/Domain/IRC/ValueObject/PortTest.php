<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\Port;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Port::class)]
final class PortTest extends TestCase
{
    #[Test]
    public function validPortIsAccepted(): void
    {
        $port = new Port(7029);

        self::assertSame(7029, $port->value);
        self::assertSame('7029', (string) $port);
    }

    #[Test]
    public function portZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out of the valid range');

        new Port(0);
    }

    #[Test]
    public function portOver65535Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out of the valid range');

        new Port(65536);
    }

    #[Test]
    public function portOneAnd65535Accepted(): void
    {
        self::assertSame(1, (new Port(1))->value);
        self::assertSame(65535, (new Port(65535))->value);
    }
}
