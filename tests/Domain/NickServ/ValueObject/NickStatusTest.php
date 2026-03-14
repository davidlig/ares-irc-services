<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\ValueObject;

use App\Domain\NickServ\ValueObject\NickStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickStatus::class)]
final class NickStatusTest extends TestCase
{
    #[Test]
    public function allCasesExistAndHaveExpectedValues(): void
    {
        self::assertSame('pending', NickStatus::Pending->value);
        self::assertSame('registered', NickStatus::Registered->value);
        self::assertSame('suspended', NickStatus::Suspended->value);
        self::assertSame('forbidden', NickStatus::Forbidden->value);
    }
}
