<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\Model;

use App\MemoServ\Application\Model\MemoChannelView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoChannelView::class)]
final class MemoChannelViewTest extends TestCase
{
    #[Test]
    public function createsInstance(): void
    {
        $channel = new MemoChannelView(5, '#Ares');

        self::assertSame(5, $channel->id);
        self::assertSame('#Ares', $channel->name);
    }
}
