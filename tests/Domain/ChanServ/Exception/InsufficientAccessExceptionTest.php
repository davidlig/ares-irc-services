<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Exception;

use App\Domain\ChanServ\Exception\InsufficientAccessException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InsufficientAccessException::class)]
final class InsufficientAccessExceptionTest extends TestCase
{
    #[Test]
    public function forChannelSetsMessageAndChannelName(): void
    {
        $e = InsufficientAccessException::forChannel('#test');

        self::assertStringContainsString('#test', $e->getMessage());
        self::assertSame('#test', $e->getChannelName());
        self::assertSame('', $e->getOperation());
    }

    #[Test]
    public function forOperationSetsMessageChannelAndOperation(): void
    {
        $e = InsufficientAccessException::forOperation('#test', 'OP');

        self::assertStringContainsString('OP', $e->getMessage());
        self::assertStringContainsString('#test', $e->getMessage());
        self::assertSame('#test', $e->getChannelName());
        self::assertSame('OP', $e->getOperation());
    }
}
