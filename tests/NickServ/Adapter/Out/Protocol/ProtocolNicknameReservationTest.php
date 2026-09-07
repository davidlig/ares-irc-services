<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Protocol;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\NickServ\Adapter\Out\Protocol\ProtocolNicknameReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolNicknameReservation::class)]
final class ProtocolNicknameReservationTest extends TestCase
{
    #[Test]
    public function delegatesReservationOperationsWhenSupported(): void
    {
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick')->with('Alice', 'reason');
        $reservation->expects(self::once())->method('releaseNick')->with('Alice');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($reservation);
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);

        $adapter = new ProtocolNicknameReservation($holder);
        $adapter->reserve('Alice', 'reason');
        $adapter->release('Alice');
    }

    #[Test]
    public function silentlySkipsOperationsWithoutAnActiveProtocol(): void
    {
        $adapter = new ProtocolNicknameReservation($this->createStub(ActiveConnectionHolderInterface::class));

        $adapter->reserve('Alice', 'reason');
        $adapter->release('Alice');
        self::addToAssertionCount(1);
    }
}
