<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNickReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UnrealUdbNickReservation::class)]
final class UnrealUdbNickReservationTest extends TestCase
{
    #[Test]
    public function reserveNickWritesForbidAndNickservMasksForNickServ(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 3, 0), $this->emptyRecords());

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
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 2, 0), $this->emptyRecords());

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
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 2, 0), $this->emptyRecords());

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
            $this->emptyRecords(),
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
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 0), $this->emptyRecords());

        $reservation->reserveNickWithDuration('GlobalBot', 86400, 'Temporary pseudo-client');

        self::assertSame([], $calls);
    }

    #[Test]
    public function releaseNickDeletesForbidAndServiceSettings(): void
    {
        $calls = [];
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 5), $this->emptyRecords());

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
        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 1), $this->emptyRecords());

        $reservation->releaseNick('MemoServ');

        self::assertSame([
            ['delete', 'N', 'MemoServ::forbid', ''],
        ], $calls);
    }

    #[Test]
    public function findManagedServiceNicksIgnoresManualForbidsAndOtherRecords(): void
    {
        $calls = [];
        $records = $this->createMock(UdbRecordRepositoryInterface::class);
        $records->expects(self::once())->method('recordsByBlock')->with('N')->willReturn([
            'OldNick::forbid' => 'Reserved for network services',
            'NiCK::FoRbId' => 'Reserved for network services',
            'Manual::forbid' => 'Operator action',
            'Other::vhost' => 'Reserved for network services',
            'Malformed::forbid::extra' => 'Reserved for network services',
            '%ZZ::forbid' => 'Reserved for network services',
        ]);

        $reservation = new UnrealUdbNickReservation($this->writingWriter($calls, 0, 0), $records);

        self::assertSame(['OldNick', 'NiCK'], $reservation->findManagedServiceNicks('Reserved for network services'));
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function failedMutationProvider(): iterable
    {
        yield 'reserve N record' => ['reserve', 'OperServ', 1, 'Could not reserve service nickname in UDB.'];
        yield 'reserve NickServ setting' => ['reserve', 'NickServ', 2, 'Could not publish NickServ identity in UDB.'];
        yield 'reserve IpServ setting' => ['reserve', 'NickServ', 3, 'Could not publish NickServ identity in UDB.'];
        yield 'reserve ChanServ setting' => ['reserve', 'ChanServ', 2, 'Could not publish ChanServ identity in UDB.'];
        yield 'release N record' => ['release', 'MemoServ', 1, 'Could not release service nickname in UDB.'];
        yield 'release NickServ setting' => ['release', 'NickServ', 2, 'Could not remove NickServ identity from UDB.'];
        yield 'release IpServ setting' => ['release', 'NickServ', 3, 'Could not remove NickServ identity from UDB.'];
        yield 'release ChanServ setting' => ['release', 'ChanServ', 2, 'Could not remove ChanServ identity from UDB.'];
    }

    #[DataProvider('failedMutationProvider')]
    #[Test]
    public function failedUdbMutationsAreReported(string $operation, string $nick, int $failOn, string $message): void
    {
        $calls = 0;
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('reserve' === $operation ? 'insert' : 'delete')->willReturnCallback(
            static function (string ...$arguments) use (&$calls, $failOn): bool {
                ++$calls;

                return $calls !== $failOn;
            },
        );
        $reservation = new UnrealUdbNickReservation($writer, $this->emptyRecords());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        if ('reserve' === $operation) {
            $reservation->reserveNick($nick, 'Reserved for network services');
        } else {
            $reservation->releaseNick($nick);
        }
    }

    private function emptyRecords(): UdbRecordRepositoryInterface
    {
        return $this->createStub(UdbRecordRepositoryInterface::class);
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
