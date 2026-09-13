<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealUdbNickReservation::class)]
final class UnrealUdbNickReservationTest extends TestCase
{
    use CreatesUdbRecordWriter;

    /** @var list<string> */
    private array $written = [];

    private UnrealUdbNickReservation $reservation;

    protected function setUp(): void
    {
        $this->written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);

        $holder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('connection');
        $property->setValue($holder, $connection);
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($holder, '001');

        $this->reservation = new UnrealUdbNickReservation($this->createUdbRecordWriter($holder));
    }

    #[Test]
    public function reserveNickPublishesNickservAndIpservMasksForNickServ(): void
    {
        $this->reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function reserveNickPublishesChanservMaskForChanServ(): void
    {
        $this->reservation->reserveNick('ChanServ', 'Reserved for network services');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function reserveNickWithCustomConfig(): void
    {
        $holder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('connection');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);
        $property->setValue($holder, $connection);
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($holder, '001');

        $res = new UnrealUdbNickReservation(
            $this->createUdbRecordWriter($holder),
            'CustomNickServ',
            'CustomIdent',
            'CustomChanServ',
            'CustomChanIdent',
            'irc.custom.org',
        );

        $res->reserveNick('customnickserv', 'reason');
        $res->reserveNick('customchanserv', 'reason');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function reserveNickIsNoOpForServicesWithoutSetting(): void
    {
        $this->reservation->reserveNick('MemoServ', 'Reserved for network services');
        $this->reservation->reserveNick('OperServ', 'Reserved for network services');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function reserveNickWithDurationIsNoOp(): void
    {
        $this->reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function releaseNickDeletesSettings(): void
    {
        $this->reservation->reserveNick('NickServ', 'Reserved for network services');
        $this->reservation->reserveNick('ChanServ', 'Reserved for network services');
        $this->written = [];

        $this->reservation->releaseNick('NickServ');
        $this->reservation->releaseNick('ChanServ');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function releaseNickIsNoOpForServicesWithoutSetting(): void
    {
        $this->reservation->releaseNick('MemoServ');

        self::assertSame([], $this->written);
    }
}
