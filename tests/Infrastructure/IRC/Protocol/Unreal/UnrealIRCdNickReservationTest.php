<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealIRCdNickReservation::class)]
final class UnrealIRCdNickReservationTest extends TestCase
{
    private array $written = [];

    #[Test]
    public function reserveNickSendsSqlineCommand(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new UnrealIRCdNickReservation();

        $reservation->reserveNick($connection, '001', 'NickServ', 'Reserved for network services');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
    }

    #[Test]
    public function reserveNickWorksForMultipleServices(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $reservation = new UnrealIRCdNickReservation();

        $reservation->reserveNick($connection, '001', 'NickServ', 'Reserved for network services');
        $reservation->reserveNick($connection, '001', 'ChanServ', 'Reserved for network services');
        $reservation->reserveNick($connection, '001', 'MemoServ', 'Reserved for network services');

        self::assertCount(3, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
        self::assertSame(':001 SQLINE ChanServ :Reserved for network services', $this->written[1]);
        self::assertSame(':001 SQLINE MemoServ :Reserved for network services', $this->written[2]);
    }
}
