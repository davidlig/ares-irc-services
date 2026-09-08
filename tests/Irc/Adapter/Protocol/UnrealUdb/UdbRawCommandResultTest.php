<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbRawCommandResult::class)]
final class UdbRawCommandResultTest extends TestCase
{
    #[Test]
    public function successStoresAuditLine(): void
    {
        $result = UdbRawCommandResult::success('RAW line');

        self::assertTrue($result->success);
        self::assertNull($result->errorKey);
        self::assertSame([], $result->errorParams);
        self::assertSame('RAW line', $result->auditLine);
    }

    #[Test]
    public function errorStoresKeyAndParameters(): void
    {
        $result = UdbRawCommandResult::error('raw.invalid', ['field' => 'password']);

        self::assertFalse($result->success);
        self::assertSame('raw.invalid', $result->errorKey);
        self::assertSame(['field' => 'password'], $result->errorParams);
        self::assertNull($result->auditLine);
    }
}
