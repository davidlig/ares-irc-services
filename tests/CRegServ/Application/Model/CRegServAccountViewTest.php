<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\Model;

use App\CRegServ\Application\Model\CRegServAccountView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CRegServAccountView::class)]
final class CRegServAccountViewTest extends TestCase
{
    #[Test]
    public function createsInstanceWithTheTimezoneDefaultingToUtc(): void
    {
        $account = new CRegServAccountView(5, 'JuanJo_Jaen', 'es');

        self::assertSame(5, $account->id);
        self::assertSame('JuanJo_Jaen', $account->nickname);
        self::assertSame('es', $account->language);
        self::assertSame('UTC', $account->timezone);
    }

    #[Test]
    public function keepsTheTimezoneWhenOneIsGiven(): void
    {
        $account = new CRegServAccountView(9, 'NetAdmin', 'en', 'Europe/Madrid');

        self::assertSame('Europe/Madrid', $account->timezone);
    }
}
