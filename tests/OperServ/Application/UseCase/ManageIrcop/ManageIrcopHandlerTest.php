<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageIrcop;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorAssignmentRecord;
use App\OperServ\Application\Port\Out\OperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\Port\Out\OperatorRoleStore;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopAction;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopListEntry;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopOutcome;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcop;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopHandler;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopResult;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopListEntry::class)]
#[CoversClass(ManageIrcop::class)]
#[CoversClass(ManageIrcopHandler::class)]
#[CoversClass(ManageIrcopResult::class)]
#[CoversClass(OperatorAccountData::class)]
#[CoversClass(OperatorAssignmentRecord::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class ManageIrcopHandlerTest extends TestCase
{
    private DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        $this->occurredAt = new DateTimeImmutable('2026-09-08T11:30:00+00:00');
    }

    #[Test]
    public function addsOperatorAndRecordsRootAuditAfterPersistenceBeforeProjection(): void
    {
        $operations = new IrcopOperationLog();
        $role = $this->role(2, 'ADMIN');
        $assignments = new MemoryOperatorAssignmentStore($operations);
        $network = new RecordingOperatorAssignmentProjection($operations);
        $audit = new RecordingIrcopAudit($operations);
        $handler = $this->handler(
            new MemoryOperatorAccountLookup(['Alice' => new OperatorAccountData(7, 'Alice', true)]),
            $assignments,
            new MemoryIrcopRoleStore(['ADMIN' => $role]),
            $network,
            $audit,
        );

        $result = $handler->handle($this->command(IrcopAction::Add, 'Alice', 'ADMIN'));

        self::assertSame(IrcopOutcome::Added, $result->outcome);
        self::assertSame(['assign', 'audit', 'apply'], $operations->entries);
        self::assertSame([[7, $role, 41, $this->occurredAt]], $assignments->assigned);
        self::assertSame([[7, 'Alice', $role]], $network->applied);
        $this->assertAudit($audit->records[0], 'IRCOP ADD', 'Alice', ['role' => 'ADMIN']);
    }

    #[Test]
    public function changesRoleAndAuditsOldAndNewRolesAfterPersistenceBeforeProjection(): void
    {
        $operations = new IrcopOperationLog();
        $oldRole = $this->role(1, 'OPER');
        $newRole = $this->role(2, 'ADMIN');
        $assignment = new OperatorAssignmentRecord(7, $oldRole, new DateTimeImmutable('2026-09-01'));
        $assignments = new MemoryOperatorAssignmentStore($operations, [7 => $assignment]);
        $network = new RecordingOperatorAssignmentProjection($operations);
        $audit = new RecordingIrcopAudit($operations);

        $result = $this->handler(
            new MemoryOperatorAccountLookup(['Alice' => new OperatorAccountData(7, 'Alice', true)]),
            $assignments,
            new MemoryIrcopRoleStore(['ADMIN' => $newRole]),
            $network,
            $audit,
        )->handle($this->command(IrcopAction::Add, 'Alice', 'ADMIN'));

        self::assertSame(IrcopOutcome::RoleChanged, $result->outcome);
        self::assertSame('OPER', $result->oldRole);
        self::assertSame(['assign', 'audit', 'replace'], $operations->entries);
        $this->assertAudit($audit->records[0], 'IRCOP ROLE CHANGE', 'Alice', ['old_role' => 'OPER', 'new_role' => 'ADMIN']);
    }

    #[Test]
    public function deletesOperatorAndAuditsAfterPersistenceBeforeProjection(): void
    {
        $operations = new IrcopOperationLog();
        $role = $this->role(1, 'OPER');
        $assignment = new OperatorAssignmentRecord(7, $role, new DateTimeImmutable('2026-09-01'));
        $assignments = new MemoryOperatorAssignmentStore($operations, [7 => $assignment]);
        $network = new RecordingOperatorAssignmentProjection($operations);
        $audit = new RecordingIrcopAudit($operations);

        $result = $this->handler(
            new MemoryOperatorAccountLookup(['Alice' => new OperatorAccountData(7, 'Alice', true)]),
            $assignments,
            new MemoryIrcopRoleStore(),
            $network,
            $audit,
        )->handle($this->command(IrcopAction::Delete, 'Alice'));

        self::assertSame(IrcopOutcome::Deleted, $result->outcome);
        self::assertSame(['remove_store', 'audit', 'remove_projection'], $operations->entries);
        self::assertSame([7], $assignments->removed);
        $this->assertAudit($audit->records[0], 'IRCOP DEL', 'Alice', ['role' => 'OPER']);
    }

    #[Test]
    public function rejectsInvalidMissingInactiveUnknownRoleAndDuplicateAssignmentsWithoutAudit(): void
    {
        $operations = new IrcopOperationLog();
        $role = $this->role(1, 'OPER');
        $accounts = new MemoryOperatorAccountLookup([
            'Inactive' => new OperatorAccountData(8, 'Inactive', false),
            'Assigned' => new OperatorAccountData(9, 'Assigned', true),
            'UnknownRole' => new OperatorAccountData(10, 'UnknownRole', true),
        ]);
        $assignments = new MemoryOperatorAssignmentStore($operations, [
            9 => new OperatorAssignmentRecord(9, $role, new DateTimeImmutable('2026-09-01')),
        ]);
        $audit = new RecordingIrcopAudit($operations);
        $handler = $this->handler($accounts, $assignments, new MemoryIrcopRoleStore(['OPER' => $role]), new RecordingOperatorAssignmentProjection($operations), $audit);

        self::assertSame(IrcopOutcome::InvalidRequest, $handler->handle($this->command(IrcopAction::Add))->outcome);
        self::assertSame(IrcopOutcome::NickNotRegistered, $handler->handle($this->command(IrcopAction::Add, 'Missing', 'OPER'))->outcome);
        self::assertSame(IrcopOutcome::NickNotActive, $handler->handle($this->command(IrcopAction::Add, 'Inactive', 'OPER'))->outcome);
        self::assertSame(IrcopOutcome::RoleNotFound, $handler->handle($this->command(IrcopAction::Add, 'UnknownRole', 'MISSING'))->outcome);
        self::assertSame(IrcopOutcome::AlreadyAssigned, $handler->handle($this->command(IrcopAction::Add, 'Assigned', 'OPER'))->outcome);
        self::assertSame([], $operations->entries);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function rejectsDeletingMissingAccountOrAssignmentWithoutAudit(): void
    {
        $operations = new IrcopOperationLog();
        $handler = $this->handler(
            new MemoryOperatorAccountLookup(['Unassigned' => new OperatorAccountData(8, 'Unassigned', true)]),
            new MemoryOperatorAssignmentStore($operations),
            new MemoryIrcopRoleStore(),
            new RecordingOperatorAssignmentProjection($operations),
            new RecordingIrcopAudit($operations),
        );

        self::assertSame(IrcopOutcome::NickNotRegistered, $handler->handle($this->command(IrcopAction::Delete, 'Missing'))->outcome);
        self::assertSame(IrcopOutcome::NotAssigned, $handler->handle($this->command(IrcopAction::Delete, 'Unassigned'))->outcome);
        self::assertSame([], $operations->entries);
    }

    #[Test]
    public function listsAssignmentsWithoutAuditAndFallsBackToNumericAccountId(): void
    {
        $operations = new IrcopOperationLog();
        $role = $this->role(1, 'OPER');
        $addedAt = new DateTimeImmutable('2026-09-01');
        $assignments = new MemoryOperatorAssignmentStore($operations, [
            7 => new OperatorAssignmentRecord(7, $role, $addedAt),
            8 => new OperatorAssignmentRecord(8, $role, $addedAt),
        ]);

        $result = $this->handler(
            new MemoryOperatorAccountLookup(accountNicknames: [7 => 'Alice']),
            $assignments,
            new MemoryIrcopRoleStore(),
            new RecordingOperatorAssignmentProjection($operations),
            new RecordingIrcopAudit($operations),
        )->handle($this->command(IrcopAction::List));

        self::assertSame(IrcopOutcome::Listed, $result->outcome);
        self::assertSame('Alice', $result->entries[0]->nickname);
        self::assertSame('8', $result->entries[1]->nickname);
        self::assertSame([], $operations->entries);
    }

    #[Test]
    public function returnsUnknownActionWithoutAudit(): void
    {
        $operations = new IrcopOperationLog();

        $result = $this->handler(
            new MemoryOperatorAccountLookup(),
            new MemoryOperatorAssignmentStore($operations),
            new MemoryIrcopRoleStore(),
            new RecordingOperatorAssignmentProjection($operations),
            new RecordingIrcopAudit($operations),
        )->handle($this->command(IrcopAction::Unknown));

        self::assertSame(IrcopOutcome::UnknownAction, $result->outcome);
        self::assertSame([], $operations->entries);
    }

    private function handler(
        OperatorAccountLookup $accounts,
        OperatorAssignmentStore $assignments,
        OperatorRoleStore $roles,
        OperatorAssignmentNetworkProjection $network,
        CommandAuditRecorder $audit,
    ): ManageIrcopHandler {
        return new ManageIrcopHandler($accounts, $assignments, $roles, $network, $audit);
    }

    private function command(IrcopAction $action, string $nickname = '', string $role = ''): ManageIrcop
    {
        return new ManageIrcop($action, 'RootOper', 41, $this->occurredAt, $nickname, $role);
    }

    private function role(int $id, string $name): OperatorRoleRecord
    {
        return new OperatorRoleRecord($id, $name, 'Role', false);
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function assertAudit(CommandAuditRecord $record, string $operation, string $target, array $metadata): void
    {
        self::assertSame(CommandAuditCategory::RootAdministration, $record->category);
        self::assertSame('OperServ', $record->service);
        self::assertSame('RootOper', $record->actor);
        self::assertSame($operation, $record->operation);
        self::assertSame($this->occurredAt, $record->occurredAt);
        self::assertSame($target, $record->target);
        self::assertSame(OperatorAuthorizationAttribute::ROOT, $record->permission);
        self::assertSame($metadata, $record->metadata);
    }
}

final class IrcopOperationLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class MemoryOperatorAccountLookup implements OperatorAccountLookup
{
    /**
     * @param array<string, OperatorAccountData> $accounts
     * @param array<int, string>                 $accountNicknames
     */
    public function __construct(private array $accounts = [], private array $accountNicknames = []) {}

    public function findIdByNickname(string $nickname): ?int
    {
        return $this->accounts[$nickname]->id ?? null;
    }

    public function findByNickname(string $nickname): ?OperatorAccountData
    {
        return $this->accounts[$nickname] ?? null;
    }

    public function findNicknameById(int $id): ?string
    {
        return $this->accountNicknames[$id] ?? null;
    }
}

final class MemoryOperatorAssignmentStore implements OperatorAssignmentStore
{
    /** @var list<array{int, OperatorRoleRecord, ?int, DateTimeImmutable}> */
    public array $assigned = [];

    /** @var list<int> */
    public array $removed = [];

    /** @param array<int, OperatorAssignmentRecord> $assignments */
    public function __construct(private readonly IrcopOperationLog $operations, private array $assignments = []) {}

    public function findByNickId(int $nickId): ?OperatorAssignmentRecord
    {
        return $this->assignments[$nickId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->assignments);
    }

    public function assign(int $nickId, OperatorRoleRecord $role, ?int $addedById, DateTimeImmutable $addedAt): void
    {
        $this->operations->entries[] = 'assign';
        $this->assigned[] = [$nickId, $role, $addedById, $addedAt];
    }

    public function remove(int $nickId): void
    {
        $this->operations->entries[] = 'remove_store';
        $this->removed[] = $nickId;
    }
}

final readonly class MemoryIrcopRoleStore implements OperatorRoleStore
{
    /** @param array<string, OperatorRoleRecord> $roles */
    public function __construct(private array $roles = []) {}

    public function findByName(string $name): ?OperatorRoleRecord
    {
        return $this->roles[$name] ?? null;
    }

    public function all(): array
    {
        return array_values($this->roles);
    }

    public function create(string $name, string $description): OperatorRoleRecord
    {
        throw new LogicException('Not used.');
    }

    public function remove(string $name): void
    {
        throw new LogicException('Not used.');
    }

    public function setPermissions(string $roleName, array $permissions): void
    {
        throw new LogicException('Not used.');
    }

    public function setUserModes(string $roleName, array $modes): void
    {
        throw new LogicException('Not used.');
    }

    public function setForcedVhostPattern(string $roleName, ?string $pattern): void
    {
        throw new LogicException('Not used.');
    }

    public function setOperclass(string $roleName, ?string $operclass): void
    {
        throw new LogicException('Not used.');
    }
}

final class RecordingOperatorAssignmentProjection implements OperatorAssignmentNetworkProjection
{
    /** @var list<array{int, string, OperatorRoleRecord}> */
    public array $applied = [];

    /** @var list<array{int, string, OperatorRoleRecord}> */
    public array $removed = [];

    public function __construct(private readonly IrcopOperationLog $operations) {}

    public function apply(int $nickId, string $nickname, OperatorRoleRecord $role): void
    {
        $this->operations->entries[] = 'apply';
        $this->applied[] = [$nickId, $nickname, $role];
    }

    public function remove(int $nickId, string $nickname, OperatorRoleRecord $role): void
    {
        $this->operations->entries[] = 'remove_projection';
        $this->removed[] = [$nickId, $nickname, $role];
    }

    public function replace(int $nickId, string $nickname, OperatorRoleRecord $oldRole, OperatorRoleRecord $newRole): void
    {
        $this->operations->entries[] = 'replace';
    }
}

final class RecordingIrcopAudit implements CommandAuditRecorder
{
    /** @var list<CommandAuditRecord> */
    public array $records = [];

    public function __construct(private readonly IrcopOperationLog $operations) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->operations->entries[] = 'audit';
        $this->records[] = $record;
    }
}
