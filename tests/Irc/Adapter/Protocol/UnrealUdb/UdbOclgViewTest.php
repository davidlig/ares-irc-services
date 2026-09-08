<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg\UdbOclgView;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(UdbOclgView::class)]
final class UdbOclgViewTest extends TestCase
{
    private const int GENERATION = 7;

    private UdbOclgView $view;

    protected function setUp(): void
    {
        $this->view = new UdbOclgView();
    }

    #[Test]
    public function readySnapshotBecomesAvailableOnlyAfterEnd(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
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
    public function invalidBeginDescriptorDiscardsTheStageWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $view = new UdbOclgView($logger);

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
        $view = new UdbOclgView($logger);
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
        $view = new UdbOclgView($logger);
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
        $view = new UdbOclgView($logger);

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

    private function frame(
        UdbFrameKind $kind,
        ?string $status = null,
        ?int $count = null,
        ?string $checksum = null,
        ?string $path = null,
        int $generation = self::GENERATION,
    ): UdbFrame {
        return new UdbFrame($kind, '001', '002', roundId: $generation, epoch: '0123456789abcdef', status: $status, count: $count, checksum: $checksum, path: $path);
    }

    /** @param array<string, string> $entries */
    private function commitReady(array $entries): void
    {
        $this->view->begin($this->frame(UdbFrameKind::OclgBegin, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->view->item($this->frame(UdbFrameKind::OclgItem, path: 'netadmin', checksum: $entries['netadmin']));
        $this->view->end($this->frame(UdbFrameKind::OclgEnd));
    }
}
