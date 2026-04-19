<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(InspIRCdNickReservation::class)]
final class InspIRCdNickReservationTest extends TestCase
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
    public function reserveNickSendsAddlineQCommand(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q NickServ 001 \d+ 0 :Reserved for network services$/', $this->written[0]);
    }

    #[Test]
    public function reserveNickWorksForMultipleServices(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');
        $reservation->reserveNick('ChanServ', 'Reserved for network services');
        $reservation->reserveNick('MemoServ', 'Reserved for network services');

        self::assertCount(3, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q NickServ 001 \d+ 0 :Reserved for network services$/', $this->written[0]);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q ChanServ 001 \d+ 0 :Reserved for network services$/', $this->written[1]);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q MemoServ 001 \d+ 0 :Reserved for network services$/', $this->written[2]);
    }

    #[Test]
    public function reserveNickWithDurationSendsAddlineQWithDuration(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q GlobalBot 001 \d+ 86400 :Temporary pseudo-client$/', $this->written[0]);
    }

    #[Test]
    public function reserveNickWithDurationZeroSendsPermanent(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 0, 'Permanent block');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE Q GlobalBot 001 \d+ 0 :Permanent block$/', $this->written[0]);
    }

    #[Test]
    public function releaseNickSendsDellineQCommand(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertCount(1, $this->written);
        self::assertSame(':001 DELLINE Q NickServ', $this->written[0]);
    }

    #[Test]
    public function releaseNickWorksForMultipleNicks(): void
    {
        $reservation = new InspIRCdNickReservation($this->connectionHolder);

        $reservation->releaseNick('NickServ');
        $reservation->releaseNick('ChanServ');
        $reservation->releaseNick('MemoServ');

        self::assertCount(3, $this->written);
        self::assertSame(':001 DELLINE Q NickServ', $this->written[0]);
        self::assertSame(':001 DELLINE Q ChanServ', $this->written[1]);
        self::assertSame(':001 DELLINE Q MemoServ', $this->written[2]);
    }

    #[Test]
    public function reserveNickDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new InspIRCdNickReservation($connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function reserveNickWithDurationDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new InspIRCdNickReservation($connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function releaseNickDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new InspIRCdNickReservation($connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertEmpty($this->written);
    }
}
