<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopCommandExecutedEvent::class)]
final class IrcopCommandExecutedEventTest extends TestCase
{
    #[Test]
    public function constructorWithAllParameters(): void
    {
        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'AdminUser',
            commandName: 'KILL',
            permission: 'operserv.kill',
            target: 'BadUser',
            targetHost: 'user@host.com',
            targetIp: '10.0.0.1',
            reason: 'Flooding',
            extra: ['duration' => '1h'],
        );

        self::assertSame('AdminUser', $event->operatorNick);
        self::assertSame('KILL', $event->commandName);
        self::assertSame('operserv.kill', $event->permission);
        self::assertSame('BadUser', $event->target);
        self::assertSame('user@host.com', $event->targetHost);
        self::assertSame('10.0.0.1', $event->targetIp);
        self::assertSame('Flooding', $event->reason);
        self::assertSame(['duration' => '1h'], $event->extra);
    }

    #[Test]
    public function constructorWithRequiredParametersOnly(): void
    {
        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'TestOper',
            commandName: 'GLINE',
            permission: 'operserv.gline',
        );

        self::assertSame('TestOper', $event->operatorNick);
        self::assertSame('GLINE', $event->commandName);
        self::assertSame('operserv.gline', $event->permission);
        self::assertNull($event->target);
        self::assertNull($event->targetHost);
        self::assertNull($event->targetIp);
        self::assertNull($event->reason);
        self::assertSame([], $event->extra);
    }

    #[Test]
    public function eventExtendsSymfonyEvent(): void
    {
        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'Admin',
            commandName: 'TEST',
            permission: 'test.permission',
        );

        self::assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}
