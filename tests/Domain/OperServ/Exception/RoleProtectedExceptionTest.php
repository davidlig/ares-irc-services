<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Exception;

use App\Domain\OperServ\Exception\RoleProtectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RoleProtectedException::class)]
final class RoleProtectedExceptionTest extends TestCase
{
    #[Test]
    public function forRoleCreatesExceptionWithCorrectMessage(): void
    {
        $e = RoleProtectedException::forRole('ServicesRoot');

        self::assertSame('The role "ServicesRoot" is protected and cannot be deleted.', $e->getMessage());
    }

    #[Test]
    public function forRoleIncludesRoleNameInMessage(): void
    {
        $e = RoleProtectedException::forRole('Admin');

        self::assertStringContainsString('Admin', $e->getMessage());
        self::assertStringContainsString('protected', $e->getMessage());
    }

    #[Test]
    public function forRoleAcceptsPreviousException(): void
    {
        $previous = new RuntimeException('Previous error');
        $e = RoleProtectedException::forRole('TestRole');
        $e = new RoleProtectedException($e->getMessage(), 0, $previous);

        self::assertSame($previous, $e->getPrevious());
    }
}
