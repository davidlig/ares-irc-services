<?php

declare(strict_types=1);

namespace App\Tests\Application\Event;

use App\Application\Command\CommandOutcome;
use App\Application\Event\CommandExecutedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CommandExecutedEvent::class)]
final class CommandExecutedEventTest extends TestCase
{
    #[Test]
    public function exposesExecutionMetadata(): void
    {
        $command = new stdClass();
        $outcome = CommandOutcome::rejected();

        $event = new CommandExecutedEvent($command, 'nickserv', 'Oper', 'DROP', 'nickserv.drop', $outcome);

        self::assertSame($command, $event->command);
        self::assertSame('nickserv', $event->serviceName);
        self::assertSame('Oper', $event->operatorNick);
        self::assertSame('DROP', $event->commandName);
        self::assertSame('nickserv.drop', $event->permission);
        self::assertSame($outcome, $event->outcome);
    }
}
