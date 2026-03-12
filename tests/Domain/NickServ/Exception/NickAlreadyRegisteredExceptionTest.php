<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Exception;

use App\Domain\NickServ\Exception\NickAlreadyRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickAlreadyRegisteredException::class)]
final class NickAlreadyRegisteredExceptionTest extends TestCase
{
    #[Test]
    public function messageIncludesNickname(): void
    {
        $e = new NickAlreadyRegisteredException('SomeNick');

        self::assertStringContainsString('SomeNick', $e->getMessage());
        self::assertStringContainsString('already registered', $e->getMessage());
    }
}
