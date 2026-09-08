<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Adapter\Out\Network\IrcNojoinActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcNojoinActions::class)]
final class IrcNojoinActionsTest extends TestCase
{
    #[Test]
    public function translatesReasonAndKicksWithRequestedLanguage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with('nojoin.reason', [], 'chanserv', 'es')->willReturn('Acceso denegado');
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('kickFromChannel')->with('#Test', '001A', 'Acceso denegado');

        new IrcNojoinActions($network, $translator, new NullLogger())->kick(
            '#Test',
            '001A',
            'Guest',
            -1,
            100,
            'es',
        );
    }
}
