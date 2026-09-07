<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Domain\Exception;

use App\MemoServ\Domain\Exception\MemoDisabledException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoDisabledException::class)]
final class MemoDisabledExceptionTest extends TestCase
{
    #[Test]
    public function forTargetSetsMessageAndTarget(): void
    {
        $e = MemoDisabledException::forTarget('TestTarget');

        self::assertSame('Message service is disabled for TestTarget.', $e->getMessage());
        self::assertSame('TestTarget', $e->target);
    }
}
