<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\PublishedEvent;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
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
