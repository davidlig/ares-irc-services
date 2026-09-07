<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Domain\Exception;

use App\MemoServ\Domain\Exception\MemoNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoNotFoundException::class)]
final class MemoNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function forIndexSetsMessage(): void
    {
        $e = MemoNotFoundException::forIndex(3);

        self::assertSame('Memo #3 not found.', $e->getMessage());
    }
}
