<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Exception;

use App\Domain\NickServ\Exception\NickNotRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickNotRegisteredException::class)]
final class NickNotRegisteredExceptionTest extends TestCase
{
    #[Test]
    public function messageIncludesNickname(): void
    {
        $e = new NickNotRegisteredException('SomeNick');

        self::assertStringContainsString('SomeNick', $e->getMessage());
        self::assertStringContainsString('not registered', $e->getMessage());
    }
}
