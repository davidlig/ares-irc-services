<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionGlineNetworkActions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ActiveConnectionGlineNetworkActions::class)]
final class ActiveConnectionGlineNetworkActionsTest extends TestCase
{
    #[Test]
    public function reportsMissingConnectionForAddAndRemove(): void
    {
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn(null);
        $connection->method('getServerSid')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error')->with(self::logicalOr(
            'GLINE: no active protocol module or server SID',
            'GLINE DEL: no active protocol module or server SID',
        ));
        $adapter = new ActiveConnectionGlineNetworkActions($connection, $logger);

        $adapter->add('*@host.test', null, 'reason');
        $adapter->remove('*@host.test');
    }

    #[Test]
    public function reportsMissingSidEvenWhenProtocolModuleExists(): void
    {
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));
        $connection->method('getServerSid')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error');
        $adapter = new ActiveConnectionGlineNetworkActions($connection, $logger);

        $adapter->add('host.test', null, 'reason');
        $adapter->remove('host.test');
    }

    #[Test]
    public function addsPermanentHostMaskUsingWildcardUser(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('addGline')->with('001', '*', 'host.test', 0, 'reason');

        $this->adapter($actions)->add('host.test', null, 'reason');
    }

    #[Test]
    public function addsTemporaryMaskAndNormalizesEmptyParts(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('addGline')->with(
            '001',
            '*',
            '*',
            self::callback(static fn (int $duration): bool => 3500 <= $duration && 3600 >= $duration),
            'reason',
        );

        $this->adapter($actions)->add('@', new DateTimeImmutable('+1 hour'), 'reason');
    }

    #[Test]
    public function clampsAlreadyExpiredDurationToZero(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('addGline')->with('001', 'ident', 'host.test', 0, 'reason');

        $this->adapter($actions)->add('ident@host.test', new DateTimeImmutable('-1 hour'), 'reason');
    }

    #[Test]
    public function removesParsedMask(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('removeGline')->with('001', 'ident', 'host.test');

        $this->adapter($actions)->remove('ident@host.test');
    }

    private function adapter(ProtocolServiceActionsInterface $actions): ActiveConnectionGlineNetworkActions
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($module);
        $connection->method('getServerSid')->willReturn('001');

        return new ActiveConnectionGlineNetworkActions($connection, $this->createStub(LoggerInterface::class));
    }
}
