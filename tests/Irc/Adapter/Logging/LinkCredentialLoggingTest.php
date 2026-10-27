<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Logging;

use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function serialize;

#[CoversClass(InspIRCdProtocolHandler::class)]
final class LinkCredentialLoggingTest extends TestCase
{
    #[Test]
    public function outgoingInspServerLogRedactsPasswordWithoutChangingWireLine(): void
    {
        $wireLines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())
            ->method('writeLine')
            ->willReturnCallback(static function (string $line) use (&$wireLines): void {
                $wireLines[] = $line;
            });
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $protocol = new InspIRCdProtocolHandler(sid: '00A', logger: $logger);
        $link = new ServerLink(
            new ServerName('services.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7029),
            new LinkPassword('outgoing-server-secret'),
            'Ares Services',
        );

        $protocol->performHandshake($connection, $link);

        self::assertContains('SERVER services.test.local outgoing-server-secret 00A :Ares Services', $wireLines);
        $records = serialize($handler->getRecords());
        self::assertStringNotContainsString('outgoing-server-secret', $records);
        self::assertStringContainsString('SERVER services.test.local ****** 00A :Ares Services', $records);
    }

    #[Test]
    public function protocolErrorDiagnosticRedactsEmbeddedCredentialBearingWireLine(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $protocol = new InspIRCdProtocolHandler(sid: '00A', logger: $logger);

        $protocol->handleIncoming(new IRCMessage(command: 'ERROR', trailing: 'PASS :error-pass-secret'), $connection);

        $records = serialize($handler->getRecords());
        self::assertStringNotContainsString('error-pass-secret', $records);
        self::assertStringContainsString('PASS :******', $records);
    }
}
