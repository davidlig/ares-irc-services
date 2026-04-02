<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdNickReservation::class)]
final class InspIRCdNickReservationTest extends TestCase
{
    private array $written = [];

    #[Test]
    public function reserveNickSendsQlineCommand(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new InspIRCdNickReservation();

        $reservation->reserveNick($connection, '001', 'NickServ', 'Reserved for network services');

        self::assertCount(1, $this->written);
        self::assertSame(':001 QLINE NickServ 0 :Reserved for network services', $this->written[0]);
    }

    #[Test]
    public function reserveNickWorksForMultipleServices(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new InspIRCdNickReservation();

        $reservation->reserveNick($connection, '001', 'NickServ', 'Reserved for network services');
        $reservation->reserveNick($connection, '001', 'ChanServ', 'Reserved for network services');
        $reservation->reserveNick($connection, '001', 'MemoServ', 'Reserved for network services');

        self::assertCount(3, $this->written);
        self::assertSame(':001 QLINE NickServ 0 :Reserved for network services', $this->written[0]);
        self::assertSame(':001 QLINE ChanServ 0 :Reserved for network services', $this->written[1]);
        self::assertSame(':001 QLINE MemoServ 0 :Reserved for network services', $this->written[2]);
    }

    #[Test]
    public function reserveNickWithDurationSendsQlineWithTimestamp(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new InspIRCdNickReservation();

        $reservation->reserveNickWithDuration($connection, '001', 'GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertCount(1, $this->written);
        self::assertSame(':001 QLINE GlobalBot 86400 :Temporary pseudo-client', $this->written[0]);
    }

    #[Test]
    public function reserveNickWithDurationZeroSendsPermanent(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new InspIRCdNickReservation();

        $reservation->reserveNickWithDuration($connection, '001', 'GlobalBot', 0, 'Permanent block');

        self::assertCount(1, $this->written);
        self::assertSame(':001 QLINE GlobalBot 0 :Permanent block', $this->written[0]);
    }
}
