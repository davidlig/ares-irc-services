<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg\UdbOclgView;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use const STR_PAD_LEFT;

#[CoversClass(UdbOclgView::class)]
final class UdbOclgViewTest extends TestCase
{
    private const int GENERATION = 7;

    private const string EPOCH = '0123456789abcdef';

    private UdbOclgView $view;

    private MutableUdbClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableUdbClock();
        $this->view = new UdbOclgView(clock: $this->clock);
        $this->view->expectEpoch(self::EPOCH);
    }

    #[Test]
    public function readySnapshotBecomesAvailableOnlyAfterEnd(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $begin = $this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries));
        $this->view->begin($begin);
        $this->view->begin($begin);
        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));

        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));

        $this->view->end($this->frame(UdbFrameKind::OclgEnd));
        self::assertTrue($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function beginWithdrawsThePreviousProjectionImmediately(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries);
        self::assertTrue($this->view->isOperclassGloballyAvailable('netadmin'));

        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries), generation: 8));
        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function incompleteSnapshotWithdrawsAllClasses(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries);
        self::assertTrue($this->view->isOperclassGloballyAvailable('netadmin'));

        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, []), generation: 8));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd, generation: 8));

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function incompleteStageRejectsItems(): void
    {
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, [])));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: str_repeat('a', 64)));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function invalidBeginDescriptorIsIgnoredWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'BROKEN', count: 1, checksum: str_repeat('a', 64)));
        self::assertFalse($view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function itemsForAnotherGenerationAreIgnored(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));

        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin'], generation: 8));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function duplicateItemDiscardsTheWholeStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);
        $entries = ['netadmin' => str_repeat('a', 64)];

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function itemOverflowDiscardsTheStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);
        $entries = ['netadmin' => str_repeat('a', 64), 'services' => str_repeat('b', 64)];

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, ['netadmin' => $entries['netadmin']])));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'services', checksum: $entries['services']));
        $view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function digestMismatchDiscardsTheSnapshot(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: str_repeat('c', 64)));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: str_repeat('a', 64)));
        $view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function endWithoutStageAndEndForAnotherGenerationAreIgnored(): void
    {
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd, generation: 99));

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function incompleteFramesAreSilentlyIgnored(): void
    {
        $this->view->begin(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002'));
        $this->view->item(new UdbFrame(UdbFrameKind::OclgItem, '001', '002'));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function resetWithdrawsEverything(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries);
        self::assertTrue($this->view->isOperclassGloballyAvailable('netadmin'));

        $this->view->reset();

        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));
        self::assertFalse($this->view->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function getAvailableOperclassesReturnsEmptyBeforeEndAndSortedListAfterReadyEnd(): void
    {
        $entries = [
            'services' => str_repeat('a', 64),
            'locop' => str_repeat('b', 64),
            'netadmin' => str_repeat('c', 64),
        ];

        self::assertSame([], $this->view->getAvailableOperclasses());

        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 3, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        self::assertSame([], $this->view->getAvailableOperclasses());

        foreach ($entries as $name => $checksum) {
            $this->view->item($this->frame(UdbFrameKind::OclgItem, path: $name, checksum: $checksum));
        }
        self::assertSame([], $this->view->getAvailableOperclasses());

        $this->view->end($this->frame(UdbFrameKind::OclgEnd));
        self::assertSame(['locop', 'netadmin', 'services'], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function getAvailableOperclassesReturnsEmptyAfterReset(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries);
        self::assertSame(['netadmin'], $this->view->getAvailableOperclasses());

        $this->view->reset();
        self::assertSame([], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function getAvailableOperclassesReturnsEmptyOnIncompleteSnapshot(): void
    {
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, [])));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertSame([], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function framesAreAcceptedOnlyForTheExpectedEpoch(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];

        $this->view->begin($this->frame(
            UdbFrameKind::OclgBegin,
            status: 'READY',
            count: 1,
            checksum: UdbOclgViewDigest::fromEntries(true, $entries),
            epoch: 'fedcba9876543210',
        ));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin'], epoch: 'fedcba9876543210'));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd, epoch: 'fedcba9876543210'));

        self::assertSame([], $this->view->getAvailableOperclasses());
        self::assertNull($this->view->nextDeadline());
    }

    #[Test]
    public function generationHighWaterRejectsCompletedDuplicateAndOlderSnapshots(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries, generation: 8);

        foreach ([8, 7] as $generation) {
            $this->view->begin($this->frame(
                UdbFrameKind::OclgBegin,
                status: 'INCOMPLETE',
                count: 0,
                checksum: UdbOclgViewDigest::fromEntries(false, []),
                generation: $generation,
            ));
        }

        self::assertSame(['netadmin'], $this->view->getAvailableOperclasses());
        self::assertNull($this->view->nextDeadline());
    }

    #[Test]
    public function generationOrderingSupportsValuesAbovePhpIntMaxFromRawWire(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $digest = UdbOclgViewDigest::fromEntries(true, $entries);
        $this->view->expectEpoch(self::EPOCH);

        $begin = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG BEGIN ' . self::EPOCH . ' 18446744073709551615 READY 1 ' . $digest));
        $item = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG ITEM ' . self::EPOCH . ' 18446744073709551615 netadmin ' . $entries['netadmin']));
        $end = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG END ' . self::EPOCH . ' 18446744073709551615'));
        self::assertNotNull($begin);
        self::assertNotNull($item);
        self::assertNotNull($end);
        $this->view->begin($begin);
        $this->view->item($item);
        $this->view->end($end);

        $older = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG BEGIN ' . self::EPOCH . ' 9223372036854775808 INCOMPLETE 0 ' . UdbOclgViewDigest::fromEntries(false, [])));
        self::assertNotNull($older);
        $this->view->begin($older);

        self::assertSame(['netadmin'], $this->view->getAvailableOperclasses());
        self::assertNull($this->view->nextDeadline());
    }

    #[Test]
    public function malformedNewerDescriptorDoesNotWithdrawTheCommittedView(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Ignored invalid OCLG BEGIN descriptor.');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);
        $entries = ['netadmin' => str_repeat('a', 64)];

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $view->end($this->frame(UdbFrameKind::OclgEnd));

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1025, checksum: str_repeat('b', 64), generation: 8));

        self::assertSame(['netadmin'], $view->getAvailableOperclasses());
        self::assertNull($view->nextDeadline());
    }

    #[Test]
    public function conflictingDescriptorForTheHighWaterGenerationIsIgnored(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Ignored conflicting OCLG high-water descriptor.');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);
        $entries = ['netadmin' => str_repeat('a', 64)];
        $digest = UdbOclgViewDigest::fromEntries(true, $entries);

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: $digest));
        $deadline = $view->nextDeadline();
        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, [])));

        self::assertSame($deadline, $view->nextDeadline());
        $view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $view->end($this->frame(UdbFrameKind::OclgEnd));
        self::assertSame(['netadmin'], $view->getAvailableOperclasses());
    }

    #[Test]
    public function abortedHighWaterGenerationCanRetryTheExactDescriptor(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $digest = UdbOclgViewDigest::fromEntries(true, $entries);
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: $digest));

        $this->clock->advance(30);
        self::assertTrue($this->view->expire());

        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: $digest));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertSame(['netadmin'], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function changingEpochWithdrawsTheViewAndResetsTheGenerationHighWater(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->commitReady($entries, generation: 8);

        $this->view->expectEpoch('fedcba9876543210');
        self::assertSame([], $this->view->getAvailableOperclasses());

        $replacement = ['services' => str_repeat('b', 64)];
        $this->view->begin($this->frame(
            UdbFrameKind::OclgBegin,
            status: 'READY',
            count: 1,
            checksum: UdbOclgViewDigest::fromEntries(true, $replacement),
            generation: 1,
            epoch: 'fedcba9876543210',
        ));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'services', checksum: $replacement['services'], generation: 1, epoch: 'fedcba9876543210'));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd, generation: 1, epoch: 'fedcba9876543210'));

        self::assertSame(['services'], $this->view->getAvailableOperclasses());

        $this->view->expectEpoch('fedcba9876543210');
        self::assertSame(['services'], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function stageHasAnAbsoluteDeadlineThatItemsCannotExtend(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));

        self::assertSame(50, $this->view->nextDeadline());
        $this->clock->advance(29);
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        self::assertSame(50, $this->view->nextDeadline());

        $this->clock->advance(1);
        self::assertTrue($this->view->expire());
        self::assertNull($this->view->nextDeadline());
        self::assertFalse($this->view->expire());

        $this->view->end($this->frame(UdbFrameKind::OclgEnd));
        self::assertSame([], $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function snapshotAcceptsExactlyTheOfficialMaximumOf1024Classes(): void
    {
        $entries = [];
        for ($index = 0; $index < 1024; ++$index) {
            $entries['class-' . $index] = str_pad(dechex($index), 64, '0', STR_PAD_LEFT);
        }

        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1024, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        foreach ($entries as $name => $digest) {
            $this->view->item($this->frame(UdbFrameKind::OclgItem, path: $name, checksum: $digest));
        }
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));

        self::assertCount(1024, $this->view->getAvailableOperclasses());
    }

    #[Test]
    public function snapshotRejectsADeclaredCountAboveTheOfficialMaximum(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Ignored invalid OCLG BEGIN descriptor.');
        $view = new UdbOclgView($logger, $this->clock);
        $view->expectEpoch(self::EPOCH);

        $view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1025, checksum: UdbOclgViewDigest::fromEntries(true, [])));

        self::assertSame([], $view->getAvailableOperclasses());
        self::assertNull($view->nextDeadline());
    }

    private function frame(
        UdbFrameKind $kind,
        ?string $status = null,
        ?int $count = null,
        ?string $checksum = null,
        ?string $path = null,
        int $generation = self::GENERATION,
        string $epoch = self::EPOCH,
    ): UdbFrame {
        return new UdbFrame($kind, '001', '002', roundId: $generation, epoch: $epoch, status: $status, count: $count, checksum: $checksum, path: $path);
    }

    /** @param array<string, string> $entries */
    private function commitReady(array $entries, int $generation = self::GENERATION): void
    {
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries), generation: $generation));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin'], generation: $generation));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd, generation: $generation));
    }
}
