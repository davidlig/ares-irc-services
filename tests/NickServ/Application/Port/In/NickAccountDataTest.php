<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Port\In;

use App\NickServ\Application\Port\In\NickAccountData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickAccountData::class)]
final class NickAccountDataTest extends TestCase
{
    #[Test]
    public function createsInstanceWithProperties(): void
    {
        $data = new NickAccountData(1, 'Alice', 'es', 'Europe/Madrid', true, false, 'alice@example.com');

        self::assertSame(1, $data->id);
        self::assertSame('Alice', $data->nickname);
        self::assertSame('es', $data->language);
        self::assertSame('Europe/Madrid', $data->timezone);
        self::assertTrue($data->registered);
        self::assertFalse($data->suspended);
        self::assertSame('alice@example.com', $data->email);
    }

    #[Test]
    public function defaultTimezoneIsUtc(): void
    {
        $data = new NickAccountData(2, 'Bob', 'en');

        self::assertSame('UTC', $data->timezone);
        self::assertTrue($data->registered);
    }
}
