<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceUidProviderInterface;
use App\Application\Shared\ServiceUidRegistry;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionGlobalMessageTransport;
use App\OperServ\Application\UseCase\Global\GlobalMessageType;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveConnectionGlobalMessageTransport::class)]
final class ActiveConnectionGlobalMessageTransportTest extends TestCase
{
    #[Test]
    public function findsServiceAndBroadcastsWhenConnected(): void
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $messages = $this->createMock(SendNoticePort::class);
        $messages->expects(self::exactly(2))->method('sendMessage')->withAnyParameters();
        $transport = $this->transport($connection, $messages, ['U1', 'U2']);

        self::assertSame('SVC001', $transport->serviceUidForNickname('NickServ'));
        self::assertSame(2, $transport->broadcastFromService('SVC001', 'hello', GlobalMessageType::Notice));
    }

    #[Test]
    public function refusesServiceBroadcastWithoutConnection(): void
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('isConnected')->willReturn(false);

        self::assertNull($this->transport($connection)->broadcastFromService('SVC001', 'hello', GlobalMessageType::Notice));
    }

    #[Test]
    public function introducesBroadcastsAndQuitsTemporaryClient(): void
    {
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::once())->method('introducePseudoClient')->with('001', 'Temp', 'ident', 'host.test', '001Z00001', 'Temp');
        $actions->expects(self::once())->method('quitPseudoClient')->with('001', '001Z00001', 'Global message completed');
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNickWithDuration')->with('Temp', 86400, 'Global message pseudo-client (sender: Oper)');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);
        $module->method('getNickReservation')->willReturn($reservation);
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($module);
        $connection->method('getServerSid')->willReturn('001');
        $messages = $this->createMock(SendNoticePort::class);
        $messages->expects(self::once())->method('sendMessage')->with('001Z00001', 'U1', 'hello', 'PRIVMSG');

        self::assertSame(1, $this->transport($connection, $messages, ['U1'])->broadcastFromTemporaryClient(GlobalMessageMask::fromString('Temp!ident@host.test'), 'hello', GlobalMessageType::PrivateMessage, 'Oper'));
    }

    #[Test]
    public function refusesTemporaryClientWithoutActiveProtocol(): void
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn(null);
        $connection->method('getServerSid')->willReturn(null);

        self::assertNull($this->transport($connection)->broadcastFromTemporaryClient(GlobalMessageMask::fromString('Temp!ident@host.test'), 'hello', GlobalMessageType::Notice, 'Oper'));
    }

    /** @param list<string> $uids */
    private function transport(ActiveConnectionHolderInterface $connection, ?SendNoticePort $messages = null, array $uids = []): ActiveConnectionGlobalMessageTransport
    {
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('listConnectedUids')->willReturn($uids);
        $messages ??= $this->createStub(SendNoticePort::class);

        return new ActiveConnectionGlobalMessageTransport(
            ServiceUidRegistry::fromIterable([new GlobalServiceUidProvider()]),
            new PseudoClientUidGenerator($connection),
            $connection,
            $users,
            $messages,
        );
    }
}

final readonly class GlobalServiceUidProvider implements ServiceUidProviderInterface
{
    public function getUid(): string
    {
        return 'SVC001';
    }

    public function getServiceKey(): string
    {
        return 'nickserv';
    }

    public function getNickname(): string
    {
        return 'NickServ';
    }
}
