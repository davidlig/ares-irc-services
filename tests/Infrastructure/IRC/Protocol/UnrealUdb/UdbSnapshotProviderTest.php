<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbSnapshotProvider::class)]
final class UdbSnapshotProviderTest extends TestCase
{
    #[Test]
    public function recordsComeFromTheAuthoritativeStoreForEveryBlock(): void
    {
        $records = $this->createStub(UdbRecordRepositoryInterface::class);
        $records->method('recordsByBlock')->willReturnCallback(
            static fn (string $block): array => 'S' === $block ? ['nickserv' => 'NickServ!NickServ@services'] : [],
        );

        $provider = new UdbSnapshotProvider($records);

        self::assertSame(
            ['nickserv' => 'NickServ!NickServ@services'],
            $provider->recordsForBlock(UdbBlock::Settings),
        );
        self::assertSame([], $provider->recordsForBlock(UdbBlock::Nicks));
    }

    #[Test]
    public function emptyValuedRecordsAreNeverServed(): void
    {
        $records = $this->createStub(UdbRecordRepositoryInterface::class);
        $records->method('recordsByBlock')->willReturn([
            '#chan::topic' => '',
            '#chan::founder' => 'david',
        ]);

        $provider = new UdbSnapshotProvider($records);

        self::assertSame(['#chan::founder' => 'david'], $provider->recordsForBlock(UdbBlock::Channels));
    }

    #[Test]
    public function checksumMatchesTheUdbDigestOfTheStoredRecords(): void
    {
        $records = $this->createStub(UdbRecordRepositoryInterface::class);
        $records->method('recordsByBlock')->willReturn(['1.2.3.4::clones' => '*5']);

        $provider = new UdbSnapshotProvider($records);

        self::assertSame(
            UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]),
            $provider->checksumForBlock(UdbBlock::Ips),
        );
    }

    #[Test]
    public function checksumsExcludeEmptyValuedRecords(): void
    {
        $records = $this->createStub(UdbRecordRepositoryInterface::class);
        $records->method('recordsByBlock')->willReturn(['#chan::topic' => '']);

        $provider = new UdbSnapshotProvider($records);

        self::assertSame(UdbChecksum::EMPTY, $provider->checksumForBlock(UdbBlock::Channels));
    }

    #[Test]
    public function emptyBlocksHashToTheEmptyChecksum(): void
    {
        $records = $this->createStub(UdbRecordRepositoryInterface::class);
        $records->method('recordsByBlock')->willReturn([]);

        $provider = new UdbSnapshotProvider($records);

        self::assertSame(UdbChecksum::EMPTY, $provider->checksumForBlock(UdbBlock::Settings));
    }
}
