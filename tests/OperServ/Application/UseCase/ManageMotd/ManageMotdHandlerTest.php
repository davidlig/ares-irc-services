<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageMotd;

use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotd;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdHandler;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdOutcome;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdResult;
use App\OperServ\Application\UseCase\ManageMotd\MotdAction;
use App\OperServ\Application\UseCase\ManageMotd\MotdListEntry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const DATE_ATOM;

#[CoversClass(ManageMotdHandler::class)]
#[CoversClass(ManageMotd::class)]
#[CoversClass(ManageMotdResult::class)]
#[CoversClass(MotdListEntry::class)]
#[CoversClass(MotdEntry::class)]
final class ManageMotdHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-08T10:15:00+00:00');
    }

    #[Test]
    public function addsMotdAndRecordsSafeAuditData(): void
    {
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('add')->with(
            'Welcome to the network',
            'NickServ',
            MessageDelivery::Interactive,
            42,
            $this->now,
            new DateTimeImmutable('2026-09-15T10:15:00+00:00'),
        )->willReturn($this->entry(4));
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (CommandAuditRecord $record): bool => CommandAuditCategory::OperatorAction === $record->category
                && 'Oper' === $record->actor
                && 'MOTD ADD' === $record->operation
                && 'NickServ' === $record->target
                && null === $record->reason
                && ['motd_id' => 4] === $record->metadata,
        ));

        $result = new ManageMotdHandler($repository, $audit)->handle($this->command(
            MotdAction::Add,
            botNickname: 'NickServ',
            delivery: MessageDelivery::Interactive,
            expiry: '7d',
            text: 'Welcome to the network',
        ));

        self::assertSame(ManageMotdOutcome::Added, $result->outcome);
        self::assertSame(4, $result->id);
    }

    #[Test]
    public function rejectsInvalidAddInputsWithoutPersistence(): void
    {
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::never())->method('add');
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $handler = new ManageMotdHandler($repository, $audit);

        self::assertSame(ManageMotdOutcome::InvalidAddRequest, $handler->handle($this->command(MotdAction::Add))->outcome);
        self::assertSame(ManageMotdOutcome::InvalidMessageType, $handler->handle($this->command(
            MotdAction::Add,
            botNickname: 'NickServ',
            delivery: null,
            expiry: '1h',
            text: 'hello',
        ))->outcome);
        self::assertSame(ManageMotdOutcome::InvalidExpiry, $handler->handle($this->command(
            MotdAction::Add,
            botNickname: 'NickServ',
            delivery: MessageDelivery::NonInteractive,
            expiry: 'never',
            text: 'hello',
        ))->outcome);
    }

    #[Test]
    public function deletesAnExistingMotdAndAuditsWithoutMessageContent(): void
    {
        $entry = $this->entry(3, text: 'Never include this in audit data');
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('findById')->with(3)->willReturn($entry);
        $repository->expects(self::once())->method('remove')->with($entry);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (CommandAuditRecord $record): bool => 'MOTD DEL' === $record->operation
                && null === $record->reason
                && ['motd_id' => 3] === $record->metadata,
        ));

        $result = new ManageMotdHandler($repository, $audit)->handle($this->command(MotdAction::Delete, id: '3'));

        self::assertSame(ManageMotdOutcome::Deleted, $result->outcome);
        self::assertSame('NickServ', $result->botNickname);
    }

    #[Test]
    public function rejectsNonNumericAndMissingMotds(): void
    {
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('findById')->with(99)->willReturn(null);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $handler = new ManageMotdHandler($repository, $audit);

        self::assertSame(ManageMotdOutcome::InvalidId, $handler->handle($this->command(MotdAction::Delete, id: 'not-an-id'))->outcome);
        self::assertSame(ManageMotdOutcome::NotFound, $handler->handle($this->command(MotdAction::Delete, id: '99'))->outcome);
    }

    #[Test]
    public function listsEntriesUsingTheCommandTimeForExpiry(): void
    {
        $expired = $this->entry(1, expiresAt: new DateTimeImmutable('2026-09-08T10:14:59+00:00'));
        $active = $this->entry(2, expiresAt: null, enabled: false);
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('findAll')->willReturn([$expired, $active]);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');

        $result = new ManageMotdHandler($repository, $audit)->handle($this->command(MotdAction::List));

        self::assertSame(ManageMotdOutcome::Listed, $result->outcome);
        self::assertTrue($result->entries[0]->expired);
        self::assertFalse($result->entries[1]->expired);
        self::assertFalse($result->entries[1]->enabled);
    }

    #[Test]
    public function returnsListEmptyWhenNoEntriesExist(): void
    {
        $repository = $this->createStub(MotdRepository::class);
        $repository->method('findAll')->willReturn([]);

        self::assertSame(
            ManageMotdOutcome::ListEmpty,
            new ManageMotdHandler($repository, $this->createStub(CommandAuditRecorder::class))
                ->handle($this->command(MotdAction::List))->outcome,
        );
    }

    #[Test]
    public function cleansExpiredEntriesAndWritesOneAggregateAuditRecord(): void
    {
        $first = $this->entry(1);
        $second = $this->entry(2);
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('findExpiredAt')->with($this->now)->willReturn([$first, $second]);
        $removed = [];
        $repository->expects(self::exactly(2))->method('remove')->willReturnCallback(
            static function (MotdEntry $entry) use (&$removed): void {
                $removed[] = $entry->id;
            },
        );
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (CommandAuditRecord $record): bool => 'MOTD CLEAN' === $record->operation
                && ['removed_count' => 2] === $record->metadata,
        ));

        $result = new ManageMotdHandler($repository, $audit)->handle($this->command(MotdAction::Clean));

        self::assertSame(ManageMotdOutcome::Cleaned, $result->outcome);
        self::assertSame(2, $result->removedCount);
        self::assertSame([1, 2], $removed);
    }

    #[Test]
    public function returnsCleanEmptyWhenThereAreNoExpiredEntries(): void
    {
        $repository = $this->createStub(MotdRepository::class);
        $repository->method('findExpiredAt')->willReturn([]);

        self::assertSame(
            ManageMotdOutcome::CleanEmpty,
            new ManageMotdHandler($repository, $this->createStub(CommandAuditRecorder::class))
                ->handle($this->command(MotdAction::Clean))->outcome,
        );
    }

    #[Test]
    public function reportsUnknownActions(): void
    {
        self::assertSame(
            ManageMotdOutcome::UnknownAction,
            new ManageMotdHandler($this->createStub(MotdRepository::class), $this->createStub(CommandAuditRecorder::class))
                ->handle($this->command(MotdAction::Unknown))->outcome,
        );
    }

    #[Test]
    #[DataProvider('validDurations')]
    public function parsesEverySupportedDuration(string $duration, ?string $expectedExpiry): void
    {
        $repository = $this->createMock(MotdRepository::class);
        $repository->expects(self::once())->method('add')->with(
            'Welcome',
            'NickServ',
            MessageDelivery::NonInteractive,
            42,
            $this->now,
            self::callback(static fn (?DateTimeImmutable $expiry): bool => $expectedExpiry === $expiry?->format(DATE_ATOM)),
        )->willReturn($this->entry(9));

        $result = new ManageMotdHandler($repository, $this->createStub(CommandAuditRecorder::class))->handle($this->command(
            MotdAction::Add,
            botNickname: 'NickServ',
            delivery: MessageDelivery::NonInteractive,
            expiry: $duration,
            text: 'Welcome',
        ));

        self::assertSame(ManageMotdOutcome::Added, $result->outcome);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function validDurations(): iterable
    {
        yield 'permanent' => ['0', null];
        yield 'seconds' => ['5s', '2026-09-08T10:15:05+00:00'];
        yield 'minutes' => ['2m', '2026-09-08T10:17:00+00:00'];
        yield 'hours' => ['3h', '2026-09-08T13:15:00+00:00'];
        yield 'days' => ['2d', '2026-09-10T10:15:00+00:00'];
    }

    private function command(
        MotdAction $action,
        ?string $botNickname = null,
        ?MessageDelivery $delivery = null,
        ?string $expiry = null,
        ?string $text = null,
        ?string $id = null,
    ): ManageMotd {
        return new ManageMotd($action, 'Oper', 42, $this->now, $botNickname, $delivery, $expiry, $text, $id);
    }

    private function entry(
        int $id,
        string $text = 'Welcome',
        ?DateTimeImmutable $expiresAt = null,
        bool $enabled = true,
    ): MotdEntry {
        return new MotdEntry($id, $text, 'NickServ', MessageDelivery::NonInteractive, $enabled, $this->now, $expiresAt, 4);
    }
}
