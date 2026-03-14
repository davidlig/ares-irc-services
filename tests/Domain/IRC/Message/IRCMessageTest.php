<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Message;

use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IRCMessage::class)]
final class IRCMessageTest extends TestCase
{
    #[Test]
    public function toRawLineBuildsCorrectFormat(): void
    {
        $msg = new IRCMessage(
            command: 'PRIVMSG',
            prefix: 'nick!ident@host',
            params: ['#chan'],
            trailing: 'Hello world',
            direction: MessageDirection::Outgoing,
        );

        self::assertSame(':nick!ident@host PRIVMSG #chan :Hello world', $msg->toRawLine());
    }

    #[Test]
    public function toRawLineWithoutPrefixOrTrailing(): void
    {
        $msg = new IRCMessage('PING', null, ['12345']);

        self::assertSame('PING 12345', $msg->toRawLine());
    }

    #[Test]
    public function fromRawLineParsesPrefixCommandParamsAndTrailing(): void
    {
        $msg = IRCMessage::fromRawLine(":nick!ident@host PRIVMSG #chan :Hello there\r\n");

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('nick!ident@host', $msg->prefix);
        self::assertSame(['#chan'], $msg->params);
        self::assertSame('Hello there', $msg->trailing);
    }

    #[Test]
    public function fromRawLineParsesWithoutPrefixOrTrailing(): void
    {
        $msg = IRCMessage::fromRawLine("PING 12345\n");

        self::assertSame('PING', $msg->command);
        self::assertNull($msg->prefix);
        self::assertSame(['12345'], $msg->params);
        self::assertNull($msg->trailing);
    }
}
