<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcAkickActions;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(IrcAkickActions::class)]
final class IrcAkickActionsTest extends TestCase
{
    #[Test]
    public function translatesSemanticEffectToBanThenKick(): void
    {
        $calls = [];
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelModes')->with('#Test', '+b', ['*!*@example.test'])
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'ban';
            });
        $network->expects(self::once())->method('kickFromChannel')->with('#Test', '001A', 'AKICK: *!*@example.test')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'kick';
            });

        new IrcAkickActions($network, new NullLogger())->banAndKick(
            '#Test',
            '001A',
            '*!*@example.test',
            'AKICK: *!*@example.test',
        );

        self::assertSame(['ban', 'kick'], $calls);
    }
}
