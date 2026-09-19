<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Connect;

use App\Irc\Application\Connect\ConnectToServerCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectToServerCommand::class)]
final class ConnectToServerCommandTest extends TestCase
{
    #[Test]
    public function holdsAllConnectionParameters(): void
    {
        $cmd = new ConnectToServerCommand(
            serverName: 'services.example.com',
            host: 'irc.example.com',
            port: 7029,
            password: 'secret',
            description: 'Ares IRC Services',
            useTls: true,
        );

        self::assertSame('services.example.com', $cmd->serverName);
        self::assertSame('irc.example.com', $cmd->host);
        self::assertSame(7029, $cmd->port);
        self::assertSame('secret', $cmd->password);
        self::assertSame('Ares IRC Services', $cmd->description);
        self::assertTrue($cmd->useTls);
        self::assertTrue($cmd->tlsVerifyPeer);
    }

    #[Test]
    public function useTlsDefaultsToFalse(): void
    {
        $cmd = new ConnectToServerCommand(
            serverName: 's.example.com',
            host: '127.0.0.1',
            port: 7029,
            password: 'p',
            description: 'Desc',
        );

        self::assertFalse($cmd->useTls);
        self::assertTrue($cmd->tlsVerifyPeer);
    }

    #[Test]
    public function tlsVerifyPeerCanBeSetToFalse(): void
    {
        $cmd = new ConnectToServerCommand(
            serverName: 's.example.com',
            host: '127.0.0.1',
            port: 7029,
            password: 'p',
            description: 'Desc',
            useTls: true,
            tlsVerifyPeer: false,
        );

        self::assertTrue($cmd->useTls);
        self::assertFalse($cmd->tlsVerifyPeer);
    }
}
