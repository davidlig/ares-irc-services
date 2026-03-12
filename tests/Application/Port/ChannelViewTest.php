<?php

declare(strict_types=1);

namespace App\Tests\Application\Port;

use App\Application\Port\ChannelView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelView::class)]
final class ChannelViewTest extends TestCase
{
    #[Test]
    public function holdsPropertiesWithDefaults(): void
    {
        $view = new ChannelView(
            name: '#test',
            modes: '+tn',
            topic: 'A topic',
            memberCount: 5,
        );

        self::assertSame('#test', $view->name);
        self::assertSame('+tn', $view->modes);
        self::assertSame('A topic', $view->topic);
        self::assertSame(5, $view->memberCount);
        self::assertSame([], $view->members);
        self::assertSame(0, $view->timestamp);
        self::assertSame([], $view->modeParams);
    }

    #[Test]
    public function getModeParamReturnsValueWhenPresent(): void
    {
        $view = new ChannelView(
            name: '#test',
            modes: '+tnk',
            topic: null,
            memberCount: 0,
            members: [],
            timestamp: 0,
            modeParams: ['k' => 'secret', 'L' => '#redirect'],
        );

        self::assertSame('secret', $view->getModeParam('k'));
        self::assertSame('#redirect', $view->getModeParam('L'));
    }

    #[Test]
    public function getModeParamReturnsNullWhenAbsent(): void
    {
        $view = new ChannelView('#test', '+n', null, 0);

        self::assertNull($view->getModeParam('k'));
    }
}
