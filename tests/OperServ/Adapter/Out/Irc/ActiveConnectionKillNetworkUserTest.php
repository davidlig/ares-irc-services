<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\LocalUserQuitSyncPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionKillNetworkUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveConnectionKillNetworkUser::class)]
final class ActiveConnectionKillNetworkUserTest extends TestCase
{
    #[Test]
    public function killsThroughActiveProtocol(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('killUser')->with('001', 'U1', 'reason');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($module);
        $connection->method('getServerSid')->willReturn('001');

        $sync = $this->createMock(LocalUserQuitSyncPort::class);
        $sync->expects(self::once())->method('apply')->with('U1', 'reason');
        self::assertTrue(new ActiveConnectionKillNetworkUser($connection, $sync)->kill('U1', 'reason'));
    }

    #[Test]
    public function refusesWhenConnectionHasNoProtocolOrServerSid(): void
    {
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn(null);
        $connection->method('getServerSid')->willReturn(null);

        self::assertFalse(new ActiveConnectionKillNetworkUser($connection, $this->createStub(LocalUserQuitSyncPort::class))->kill('U1', 'reason'));
    }
}
