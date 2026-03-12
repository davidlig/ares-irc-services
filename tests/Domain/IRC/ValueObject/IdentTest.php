<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\Ident;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ident::class)]
final class IdentTest extends TestCase
{
    #[Test]
    public function validIdentIsAccepted(): void
    {
        $ident = new Ident('user');

        self::assertSame('user', $ident->value);
        self::assertSame('user', (string) $ident);
    }

    #[Test]
    public function emptyIdentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ident cannot be empty');

        new Ident('');
    }
}
