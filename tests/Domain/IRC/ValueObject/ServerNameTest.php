<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\ServerName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerName::class)]
final class ServerNameTest extends TestCase
{
    #[Test]
    public function validFqdnIsAccepted(): void
    {
        $name = new ServerName('services.example.com');

        self::assertSame('services.example.com', $name->value);
        self::assertSame('services.example.com', (string) $name);
    }

    #[Test]
    public function emptyServerNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Server name cannot be empty');

        new ServerName('');
    }

    #[Test]
    public function mustContainDot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a FQDN containing at least one dot');

        new ServerName('localhost');
    }
}
