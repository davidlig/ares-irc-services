<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\Model;

use App\MemoServ\Application\Model\MemoAccountView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoAccountView::class)]
final class MemoAccountViewTest extends TestCase
{
    #[Test]
    public function createsInstance(): void
    {
        $account = new MemoAccountView(1, 'Alice', 'en', 'Europe/London');

        self::assertSame(1, $account->id);
        self::assertSame('Alice', $account->nickname);
        self::assertSame('en', $account->language);
        self::assertSame('Europe/London', $account->timezone);
    }
}
