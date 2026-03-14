<?php

declare(strict_types=1);

namespace App\Tests\Domain\MemoServ\Exception;

use App\Domain\MemoServ\Exception\MemoNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MemoNotFoundException::class)]
final class MemoNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function forIndexCreatesExceptionWithIndex(): void
    {
        $e = MemoNotFoundException::forIndex(5);

        self::assertSame('Memo #5 not found.', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('not found', $e->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $e = MemoNotFoundException::forIndex(1);

        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
