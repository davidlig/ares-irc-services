<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\LinkPassword;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkPassword::class)]
final class LinkPasswordTest extends TestCase
{
    #[Test]
    public function nonEmptyPasswordAccepted(): void
    {
        $pw = new LinkPassword('secret');

        self::assertSame('secret', $pw->value);
        self::assertSame('secret', (string) $pw);
    }

    #[Test]
    public function emptyPasswordThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Link password cannot be empty');

        new LinkPassword('');
    }
}
