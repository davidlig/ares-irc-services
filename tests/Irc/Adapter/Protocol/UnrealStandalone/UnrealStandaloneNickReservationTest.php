<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealStandaloneNickReservation::class)]
final class UnrealStandaloneNickReservationTest extends TestCase
{
    /** @var list<string> */
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
        $property->setValue($this->connectionHolder, $connection);

        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($this->connectionHolder, '001');
    }

    #[Test]
    public function reserveNickSendsSqlineCommand(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
    }

    #[Test]
    public function reserveNickWorksForMultipleServices(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');
        $reservation->reserveNick('ChanServ', 'Reserved for network services');
        $reservation->reserveNick('MemoServ', 'Reserved for network services');

        self::assertCount(3, $this->written);
        self::assertSame(':001 SQLINE NickServ :Reserved for network services', $this->written[0]);
        self::assertSame(':001 SQLINE ChanServ :Reserved for network services', $this->written[1]);
        self::assertSame(':001 SQLINE MemoServ :Reserved for network services', $this->written[2]);
    }

    #[Test]
    public function reserveNickWithDurationSendsTimedQline(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^TKL \+ Q \* GlobalBot 001 \d+ \d+ :Temporary pseudo-client$/', $this->written[0]);
    }

    #[Test]
    public function reserveNickWithDurationZeroSendsPermanent(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 0, 'Permanent block');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^TKL \+ Q \* GlobalBot 001 0 \d+ :Permanent block$/', $this->written[0]);
    }

    #[Test]
    public function releaseNickSendsUnsqlineCommand(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertCount(1, $this->written);
        self::assertSame(':001 UNSQLINE NickServ', $this->written[0]);
    }

    #[Test]
    public function releaseNickWorksForMultipleNicks(): void
    {
        $reservation = new UnrealStandaloneNickReservation($this->connectionHolder);

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
        $reservation = new UnrealStandaloneNickReservation($connectionHolder);

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function reserveNickWithDurationDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new UnrealStandaloneNickReservation($connectionHolder);

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function releaseNickDoesNothingWhenNoServerSid(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $reservation = new UnrealStandaloneNickReservation($connectionHolder);

        $reservation->releaseNick('NickServ');

        self::assertEmpty($this->written);
    }
}
