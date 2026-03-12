<?php

declare(strict_types=1);

namespace App\Tests\Domain\MemoServ\Exception;

use App\Domain\MemoServ\Exception\MemoDisabledException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoDisabledException::class)]
final class MemoDisabledExceptionTest extends TestCase
{
    #[Test]
    public function forTargetCreatesExceptionWithTarget(): void
    {
        $e = MemoDisabledException::forTarget('SomeNick');

        self::assertStringContainsString('SomeNick', $e->getMessage());
        self::assertSame('SomeNick', $e->target);
    }
}
