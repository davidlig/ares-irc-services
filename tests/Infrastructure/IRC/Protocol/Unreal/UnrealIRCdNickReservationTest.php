<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealIRCdNickReservation::class)]
final class UnrealIRCdNickReservationTest extends TestCase
{
    private array $written = [];

    private ActiveConnectionHolder $connectionHolder;

    protected function setUp(): void
    {
        $this->written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $this->connectionHolder = new ActiveConnectionHolder();

        $reflection = new ReflectionClass($this->connectionHolder);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($this->connectionHolder, $connection);

        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setAccessible(true);
        $sidProperty->setValue($this->connectionHolder, '001');
    }

    #[Test]
    public function reserveNickSendsSqlineCommand(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
    }

    #[Test]
    public function reserveNickWorksForMultipleServices(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');
        $reservation->reserveNick('ChanServ', 'Reserved for network services');
        $reservation->reserveNick('MemoServ', 'Reserved for network services');

        self::assertCount(3, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
        self::assertSame(':001 SQLINE ChanServ :Reserved for network services', $this->written[1]);
        self::assertSame(':001 SQLINE MemoServ :Reserved for network services', $this->written[2]);
    }

    #[Test]
    public function reserveNickWithDurationSendsSqlineWithTimestamp(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SQLINE GlobalBot 86400 :Temporary pseudo-client', $this->written[0]);
    }

    #[Test]
    public function reserveNickWithDurationZeroSendsPermanent(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 0, 'Permanent block');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SQLINE GlobalBot 0 :Permanent block', $this->written[0]);
    }

    #[Test]
    public function releaseNickSendsUnsqlineCommand(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertCount(1, $this->written);
        self::assertSame(':001 UNSQLINE NickServ', $this->written[0]);
    }

    #[Test]
    public function releaseNickWorksForMultipleNicks(): void
    {
        $reservation = new UnrealIRCdNickReservation($this->connectionHolder);

        $reservation->releaseNick('NickServ');
        $reservation->releaseNick('ChanServ');
        $reservation->releaseNick('MemoServ');

        self::assertCount(3, $this->written);
        self::assertSame(':001 UNSQLINE NickServ', $this->written[0]);
        self::assertSame(':001 UNSQLINE ChanServ', $this->written[1]);
        self::assertSame(':001 UNSQLINE MemoServ', $this->written[2]);
    }

    #[Test]
    public function reserveNickDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new UnrealIRCdNickReservation($connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function reserveNickWithDurationDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new UnrealIRCdNickReservation($connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function releaseNickDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new UnrealIRCdNickReservation($connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertEmpty($this->written);
    }
}
