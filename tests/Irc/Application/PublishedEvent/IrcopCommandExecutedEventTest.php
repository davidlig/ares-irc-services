<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\PublishedEvent;

use App\Irc\Application\PublishedEvent\IrcopCommandExecutedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopCommandExecutedEvent::class)]
final class IrcopCommandExecutedEventTest extends TestCase
{
    #[Test]
    public function exposesIrcopCommandAuditMetadata(): void
    {
        $extra = ['channel' => '#ares'];
        $event = new IrcopCommandExecutedEvent(
            'ChanServ',
            'Oper',
            'DROP',
            'chanserv.drop',
            '#ares',
            'oper.example.test',
            '192.0.2.10',
            'requested by operator',
            $extra,
        );

        self::assertSame('ChanServ', $event->serviceName);
        self::assertSame('Oper', $event->operatorNick);
        self::assertSame('DROP', $event->commandName);
        self::assertSame('chanserv.drop', $event->permission);
        self::assertSame('#ares', $event->target);
        self::assertSame('oper.example.test', $event->targetHost);
        self::assertSame('192.0.2.10', $event->targetIp);
        self::assertSame('requested by operator', $event->reason);
        self::assertSame($extra, $event->extra);
    }

    #[Test]
    public function defaultsOptionalAuditMetadata(): void
    {
        $event = new IrcopCommandExecutedEvent('NickServ', 'Oper', 'FORBID', 'nickserv.forbid');

        self::assertNull($event->target);
        self::assertNull($event->targetHost);
        self::assertNull($event->targetIp);
        self::assertNull($event->reason);
        self::assertSame([], $event->extra);
    }
}
