<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbNickReservation::class)]
final class UnrealUdbNickReservationTest extends TestCase
{
    #[Test]
    public function reserveNickWritesForbidAndNickservMasksForNickServ(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 3, 0));

        $reservation->reserveNick('NickServ', 'Reserved for network services');

        self::assertSame([
            ['insert', 'N', 'NickServ::forbid', 'Reserved for network services'],
            ['insert', 'S', 'nickserv', 'NickServ!NickServ@services.davidlig.net'],
            ['insert', 'S', 'ipserv', 'NickServ!NickServ@services.davidlig.net'],
        ], $calls);
    }

    #[Test]
    public function reserveNickWritesForbidAndChanservMaskForChanServ(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 2, 0));

        $reservation->reserveNick('ChanServ', 'Reserved for network services');

        self::assertSame([
            ['insert', 'N', 'ChanServ::forbid', 'Reserved for network services'],
            ['insert', 'S', 'chanserv', 'ChanServ!ChanServ@services.davidlig.net'],
        ], $calls);
    }

    #[Test]
    public function reserveNickWritesOnlyForbidForNicksWithoutServiceSetting(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 2, 0));

        $reservation->reserveNick('MemoServ', 'Reserved for network services');
        $reservation->reserveNick('OperServ', 'Reserved for network services');

        self::assertSame([
            ['insert', 'N', 'MemoServ::forbid', 'Reserved for network services'],
            ['insert', 'N', 'OperServ::forbid', 'Reserved for network services'],
        ], $calls);
    }

    #[Test]
    public function reserveNickHonoursCustomServiceConfiguration(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation(
            $this->writingWriter($calls, 5, 0),
            'CustomNickServ',
            'CustomIdent',
            'CustomChanServ',
            'CustomChanIdent',
            'irc.custom.org',
        );

        $reservation->reserveNick('customnickserv', 'reason');
        $reservation->reserveNick('customchanserv', 'reason');

        self::assertSame([
            ['insert', 'N', 'customnickserv::forbid', 'reason'],
            ['insert', 'S', 'nickserv', 'customnickserv!CustomIdent@irc.custom.org'],
            ['insert', 'S', 'ipserv', 'customnickserv!CustomIdent@irc.custom.org'],
            ['insert', 'N', 'customchanserv::forbid', 'reason'],
            ['insert', 'S', 'chanserv', 'customchanserv!CustomChanIdent@irc.custom.org'],
        ], $calls);
    }

    #[Test]
    public function reserveNickWithDurationWritesNothing(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 0));

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertSame([], $calls);
    }

    #[Test]
    public function releaseNickDeletesForbidAndServiceSettings(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 5));

        $reservation->releaseNick('NickServ');
        $reservation->releaseNick('ChanServ');

        self::assertSame([
            ['delete', 'N', 'NickServ::forbid', ''],
            ['delete', 'S', 'nickserv', ''],
            ['delete', 'S', 'ipserv', ''],
            ['delete', 'N', 'ChanServ::forbid', ''],
            ['delete', 'S', 'chanserv', ''],
        ], $calls);
    }

    #[Test]
    public function releaseNickDeletesOnlyForbidForNicksWithoutServiceSetting(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 1));

        $reservation->releaseNick('MemoServ');

        self::assertSame([
            ['delete', 'N', 'MemoServ::forbid', ''],
        ], $calls);
    }

    /**
     * @param list<array{string, string, string, string}> $calls
     */
    private function writingWriter(array &$calls, int $insertCalls, int $deleteCalls): UdbRecordWriterInterface
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects(self::exactly($insertCalls))->method('insert')->willReturnCallback(
            static function (string $block, string $path, string $value) use (&$calls): bool {
                $calls[] = ['insert', $block, $path, $value];

                return true;
            },
        );
        $writer->expects(self::exactly($deleteCalls))->method('delete')->willReturnCallback(
            static function (string $block, string $path) use (&$calls): bool {
                $calls[] = ['delete', $block, $path, ''];

                return true;
            },
        );

        return $writer;
    }
}
