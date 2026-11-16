<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\RemoteBurstWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteBurstWindow::class)]
final class RemoteBurstWindowTest extends TestCase
{
    #[Test]
    public function itStartsClosedAndFollowsOpenAndClose(): void
    {
        $window = new RemoteBurstWindow();
        self::assertFalse($window->isOpen());

        $window->open();
        self::assertTrue($window->isOpen());

        $window->close();
        self::assertFalse($window->isOpen());
    }
}
