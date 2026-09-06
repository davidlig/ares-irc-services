<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\History;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\History\HistoryNick;
use App\NickServ\Application\UseCase\History\HistoryNickAction;
use App\NickServ\Application\UseCase\History\HistoryNickHandler;
use App\NickServ\Application\UseCase\History\HistoryNickOutcome;
use App\NickServ\Application\UseCase\History\HistoryNickResult;
use App\NickServ\Application\UseCase\History\NickHistoryEntryView;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryNickHandler::class)]
#[CoversClass(HistoryNick::class)]
#[CoversClass(HistoryNickResult::class)]
#[CoversClass(NickHistoryEntryView::class)]
final class HistoryNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsNotRegisteredWhenAccountNotFound(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $this->createStub(NickHistoryRepositoryInterface::class),
            $this->createHistoryService(),
        );

        $result = $handler->handle(new HistoryNick(nickname: 'Unknown', action: HistoryNickAction::View));

        self::assertSame(HistoryNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('Unknown', $result->targetNick);
    }

    #[Test]
    public function addsHistoryEntrySuccessfully(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('TargetNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('save')->with(self::callback(static fn (NickHistory $h): bool => 10 === $h->getNickId()
                && 'HISTORY_ADD' === $h->getAction()
                && 'Oper' === $h->getPerformedBy()
                && 1 === $h->getPerformedByNickId()
                && 'Manual note added' === $h->getMessage()
                && '127.0.0.1' === ($h->getExtraData()['ip'] ?? null)
                && 'oper@box' === ($h->getExtraData()['host'] ?? null)));

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
        );

        $result = $handler->handle(new HistoryNick(
            nickname: 'TargetNick',
            action: HistoryNickAction::Add,
            message: 'Manual note added',
            operatorNick: 'Oper',
            operatorNickId: 1,
            operatorIp: '127.0.0.1',
            operatorHost: 'oper@box',
        ));

        self::assertSame(HistoryNickOutcome::AddSuccess, $result->outcome);
        self::assertSame('TargetNick', $result->targetNick);
        self::assertSame('Manual note added', $result->message);
    }

    #[Test]
    public function returnsDelInvalidIdWhenIdNullOrNonPositive(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $this->createStub(NickHistoryRepositoryInterface::class),
            $this->createHistoryService(),
        );

        $result = $handler->handle(new HistoryNick(
            nickname: 'TargetNick',
            action: HistoryNickAction::Del,
            entryId: 0,
        ));

        self::assertSame(HistoryNickOutcome::DelInvalidId, $result->outcome);
        self::assertSame('0', $result->rawId);
    }

    #[Test]
    public function returnsDelNotFoundWhenEntryMissingOrBelongsToOtherNick(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::exactly(2))->method('findById')->willReturnMap([
            [100, null],
            [101, $this->createEntry(id: 101, nickId: 999)],
        ]);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
        );

        $resultMissing = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::Del, entryId: 100));
        self::assertSame(HistoryNickOutcome::DelNotFound, $resultMissing->outcome);
        self::assertSame(100, $resultMissing->entryId);

        $resultOther = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::Del, entryId: 101));
        self::assertSame(HistoryNickOutcome::DelNotFound, $resultOther->outcome);
        self::assertSame(101, $resultOther->entryId);
    }

    #[Test]
    public function deletesEntrySuccessfully(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('findById')->with(55)
            ->willReturn($this->createEntry(id: 55, nickId: 10));
        $historyRepo->expects(self::once())->method('deleteById')->with(55);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
        );

        $result = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::Del, entryId: 55));

        self::assertSame(HistoryNickOutcome::DelSuccess, $result->outcome);
        self::assertSame('TargetNick', $result->targetNick);
        self::assertSame(55, $result->entryId);
    }

    #[Test]
    public function clearsAllHistorySuccessfully(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('deleteByNickId')->with(10)->willReturn(7);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
        );

        $result = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::Clear));

        self::assertSame(HistoryNickOutcome::ClearSuccess, $result->outcome);
        self::assertSame('TargetNick', $result->targetNick);
        self::assertSame(7, $result->deletedCount);
    }

    #[Test]
    public function returnsViewNoEntriesWhenTotalIsZero(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('countByNickId')->with(10)->willReturn(0);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
        );

        $result = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::View));

        self::assertSame(HistoryNickOutcome::ViewNoEntries, $result->outcome);
        self::assertSame('TargetNick', $result->targetNick);
    }

    #[Test]
    public function returnsViewSuccessWithPaginatedEntries(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $operatorAccount = $this->createStub(RegisteredNick::class);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('TargetNick')->willReturn($account);
        $nickRepo->expects(self::exactly(2))->method('findById')->willReturnMap([
            [1, $operatorAccount],
            [999, null],
        ]);

        $entry1 = $this->createEntry(id: 1, nickId: 10, performedByNickId: 1);
        $entry2 = $this->createEntry(id: 2, nickId: 10, performedByNickId: 999);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('countByNickId')->with(10)->willReturn(25);
        $historyRepo->expects(self::once())->method('findByNickId')->with(10, 10, 10)->willReturn([$entry1, $entry2]);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
            historyViewLimit: 10,
        );

        $result = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::View, page: 2));

        self::assertSame(HistoryNickOutcome::ViewSuccess, $result->outcome);
        self::assertSame('TargetNick', $result->targetNick);
        self::assertSame(25, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(3, $result->totalPages);
        self::assertSame(11, $result->start);
        self::assertSame(20, $result->end);
        self::assertCount(2, $result->entries);

        self::assertSame(1, $result->entries[0]->id);
        self::assertTrue($result->entries[0]->operatorExists);

        self::assertSame(2, $result->entries[1]->id);
        self::assertFalse($result->entries[1]->operatorExists);
    }

    #[Test]
    public function returnsViewSuccessWithShowAll(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('countByNickId')->with(10)->willReturn(5);
        $historyRepo->expects(self::once())->method('findByNickId')->with(10, null, 0)->willReturn([
            $this->createEntry(id: 1, nickId: 10),
        ]);

        $handler = new HistoryNickHandler(
            $nickRepo,
            $historyRepo,
            $this->createHistoryService($historyRepo),
            historyViewLimit: 2,
        );

        $result = $handler->handle(new HistoryNick(nickname: 'TargetNick', action: HistoryNickAction::View, showAll: true));

        self::assertSame(HistoryNickOutcome::ViewSuccess, $result->outcome);
        self::assertTrue($result->showAll);
        self::assertSame(1, $result->totalPages);
        self::assertSame(1, $result->start);
        self::assertSame(5, $result->end);
    }

    private function createHistoryService(?NickHistoryRepositoryInterface $historyRepo = null): NickHistoryService
    {
        return new NickHistoryService($historyRepo ?? $this->createStub(NickHistoryRepositoryInterface::class));
    }

    private function createEntry(
        int $id,
        int $nickId,
        ?int $performedByNickId = null,
    ): NickHistory {
        return new NickHistory(
            id: $id,
            nickId: $nickId,
            action: 'TEST_ACTION',
            performedBy: 'Oper',
            performedByNickId: $performedByNickId,
            performedAt: new DateTimeImmutable('2026-09-06 12:00:00 UTC'),
            message: 'test message',
            extraData: [],
        );
    }
}
