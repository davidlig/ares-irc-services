<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Model;

use App\ChanServ\Application\Model\ChanAccountView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanAccountView::class)]
final class ChanAccountViewTest extends TestCase
{
    #[Test]
    public function exposesAccountDataAndDefaultValues(): void
    {
        $account = new ChanAccountView(42, 'Alice', 'es');

        self::assertSame(42, $account->id);
        self::assertSame('Alice', $account->nickname);
        self::assertSame('es', $account->language);
        self::assertSame('UTC', $account->timezone);
        self::assertTrue($account->registered);
        self::assertFalse($account->suspended);
        self::assertNull($account->email);
    }

    #[Test]
    public function exposesExplicitOptionalAccountData(): void
    {
        $account = new ChanAccountView(7, 'Bob', 'ca', 'Europe/Madrid', false, true, 'bob@example.test');

        self::assertSame('Europe/Madrid', $account->timezone);
        self::assertFalse($account->registered);
        self::assertTrue($account->suspended);
        self::assertSame('bob@example.test', $account->email);
    }
}
