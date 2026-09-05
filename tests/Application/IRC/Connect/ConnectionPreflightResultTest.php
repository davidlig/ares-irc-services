<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC\Connect;

use App\Application\IRC\Connect\ConnectionPreflightResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPreflightResult::class)]
final class ConnectionPreflightResultTest extends TestCase
{
    #[Test]
    public function exposesReadinessAndOptionalMessage(): void
    {
        $result = new ConnectionPreflightResult(false, 'Not ready.');

        self::assertFalse($result->ready);
        self::assertSame('Not ready.', $result->message);
    }
}
