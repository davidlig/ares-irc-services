<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Exception;

use App\Domain\NickServ\Exception\InvalidCredentialsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidCredentialsException::class)]
final class InvalidCredentialsExceptionTest extends TestCase
{
    #[Test]
    public function messageIndicatesInvalidCredentials(): void
    {
        $e = new InvalidCredentialsException();

        self::assertStringContainsString('Invalid', $e->getMessage());
        self::assertStringContainsString('password', $e->getMessage());
    }
}
