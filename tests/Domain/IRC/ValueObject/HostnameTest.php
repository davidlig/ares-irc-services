<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\Hostname;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hostname::class)]
final class HostnameTest extends TestCase
{
    #[Test]
    public function validDomainIsAccepted(): void
    {
        $host = new Hostname('irc.example.com');

        self::assertSame('irc.example.com', $host->value);
        self::assertSame('irc.example.com', (string) $host);
    }

    #[Test]
    public function validIpIsAccepted(): void
    {
        $host = new Hostname('127.0.0.1');

        self::assertSame('127.0.0.1', $host->value);
    }

    #[Test]
    public function emptyHostnameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hostname cannot be empty');

        new Hostname('');
    }

    #[Test]
    public function invalidHostnameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid hostname or IP');

        new Hostname('not valid..host');
    }
}
