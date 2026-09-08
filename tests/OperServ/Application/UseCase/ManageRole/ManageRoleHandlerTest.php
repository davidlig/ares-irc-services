<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageRole;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\Out\ForcedVhostPolicy;
use App\OperServ\Application\Port\Out\OperatorModeCatalog;
use App\OperServ\Application\Port\Out\OperatorPermissionCatalog;
use App\OperServ\Application\Port\Out\OperatorRoleNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\Port\Out\OperatorRoleStore;
use App\OperServ\Application\UseCase\ManageRole\ManageRole;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleHandler;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleResult;
use App\OperServ\Application\UseCase\ManageRole\RoleAction;
use App\OperServ\Application\UseCase\ManageRole\RoleOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageRole::class)]
#[CoversClass(ManageRoleHandler::class)]
#[CoversClass(ManageRoleResult::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class ManageRoleHandlerTest extends TestCase
{
    private DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        $this->occurredAt = new DateTimeImmutable('2026-09-08T12:30:00+00:00');
    }

    /**
     * @param list<string>                              $permissionCatalog
     * @param list<string>|null                         $modeCatalog
     * @param list<string>|null                         $operclassCatalog
     * @param list<string>                              $expectedTrace
     * @param array<string, bool|float|int|string|null> $expectedMetadata
     */
    #[Test]
    #[DataProvider('effectiveMutations')]
    public function auditsEveryEffectiveMutationAfterPersistenceAndBeforeNetworkProjection(
        RoleAction $action,
        ?OperatorRoleRecord $existingRole,
        string $roleName,
        string $value,
        string $description,
        array $permissionCatalog,
        ?array $modeCatalog,
        bool $validVhost,
        bool $supportsOperclass,
        ?array $operclassCatalog,
        RoleOutcome $expectedOutcome,
        array $expectedTrace,
        string $expectedOperation,
        array $expectedMetadata,
    ): void {
        $trace = new RoleOperationTrace();
        $roles = new MemoryOperatorRoleStore($trace, null === $existingRole ? [] : [$existingRole->name => $existingRole]);
        $network = new RecordingOperatorRoleNetworkProjection($trace, $supportsOperclass, $operclassCatalog);
        $audit = new RecordingRoleAudit($trace);
        $handler = $this->handler($roles, $permissionCatalog, $network, $modeCatalog, $validVhost, $audit);

        $result = $handler->handle($this->command($action, $roleName, $value, $description));

        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame($expectedTrace, $trace->entries);
        self::assertCount(1, $audit->records);
        $this->assertAudit($audit->records[0], $expectedOperation, 'CUSTOM' === trim(strtoupper($roleName)) ? 'CUSTOM' : 'ADMIN', $expectedMetadata);
    }

    /**
     * @return iterable<string, array{
     *     RoleAction,
     *     OperatorRoleRecord|null,
     *     string,
     *     string,
     *     string,
     *     list<string>,
     *     list<string>|null,
     *     bool,
     *     bool,
     *     list<string>|null,
     *     RoleOutcome,
     *     list<string>,
     *     string,
     *     array<string, bool|float|int|string|null>
     * }>
     */
    public static function effectiveMutations(): iterable
    {
        yield 'add' => [
            RoleAction::Add,
            null,
            ' custom ',
            '',
            'Custom description',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::Added,
            ['store:create', 'audit'],
            'ROLE ADD',
            [],
        ];
        yield 'delete' => [
            RoleAction::Delete,
            self::role(),
            'admin',
            '',
            '',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::Deleted,
            ['store:remove', 'audit'],
            'ROLE DEL',
            [],
        ];
        yield 'permission add' => [
            RoleAction::PermissionAdd,
            self::role(permissions: ['operserv.global']),
            'admin',
            'operserv.kill',
            '',
            ['operserv.global', 'operserv.kill'],
            [],
            true,
            false,
            null,
            RoleOutcome::PermissionAdded,
            ['store:permissions', 'audit'],
            'ROLE PERMS ADD',
            ['permission_name' => 'operserv.kill'],
        ];
        yield 'permission add all' => [
            RoleAction::PermissionAddAll,
            self::role(permissions: ['operserv.global']),
            'admin',
            '',
            '',
            ['operserv.global', 'operserv.kill'],
            [],
            true,
            false,
            null,
            RoleOutcome::PermissionAddedAll,
            ['store:permissions', 'audit'],
            'ROLE PERMS ADD ALL',
            ['added_count' => 1],
        ];
        yield 'permission delete' => [
            RoleAction::PermissionDelete,
            self::role(permissions: ['operserv.global', 'operserv.kill']),
            'admin',
            'operserv.kill',
            '',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::PermissionRemoved,
            ['store:permissions', 'audit'],
            'ROLE PERMS DEL',
            ['permission_name' => 'operserv.kill'],
        ];
        yield 'permission clear' => [
            RoleAction::PermissionClear,
            self::role(permissions: ['operserv.global', 'operserv.kill']),
            'admin',
            '',
            '',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::PermissionsCleared,
            ['store:permissions', 'audit'],
            'ROLE PERMS CLEAR',
            ['removed_count' => 2],
        ];
        yield 'modes set' => [
            RoleAction::ModesSet,
            self::role(modes: ['o']),
            'admin',
            '+so',
            '',
            [],
            ['o', 's'],
            true,
            false,
            null,
            RoleOutcome::ModesSet,
            ['store:modes', 'audit', 'network:modes'],
            'ROLE MODES SET',
            ['modes' => 'so'],
        ];
        yield 'modes clear' => [
            RoleAction::ModesSet,
            self::role(modes: ['o']),
            'admin',
            '',
            '',
            [],
            ['o'],
            true,
            false,
            null,
            RoleOutcome::ModesCleared,
            ['store:modes', 'audit', 'network:modes'],
            'ROLE MODES CLEAR',
            [],
        ];
        yield 'vhost set' => [
            RoleAction::VhostSet,
            self::role(vhost: 'old.example.test'),
            'admin',
            'new.example.test',
            '',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::VhostSet,
            ['store:vhost', 'audit', 'network:vhost'],
            'ROLE VHOST SET',
            ['vhost' => 'new.example.test'],
        ];
        yield 'vhost clear' => [
            RoleAction::VhostSet,
            self::role(vhost: 'old.example.test'),
            'admin',
            'OFF',
            '',
            [],
            [],
            true,
            false,
            null,
            RoleOutcome::VhostCleared,
            ['store:vhost', 'audit', 'network:vhost'],
            'ROLE VHOST CLEAR',
            [],
        ];
        yield 'operclass set' => [
            RoleAction::OperclassSet,
            self::role(operclass: 'old-class'),
            'admin',
            'netadmin',
            '',
            [],
            [],
            true,
            true,
            ['NetAdmin'],
            RoleOutcome::OperclassSet,
            ['store:operclass', 'audit', 'network:operclass'],
            'ROLE OPERCLASS SET',
            ['operclass' => 'NetAdmin'],
        ];
        yield 'operclass clear' => [
            RoleAction::OperclassSet,
            self::role(operclass: 'NetAdmin'),
            'admin',
            'OFF',
            '',
            [],
            [],
            true,
            true,
            ['NetAdmin'],
            RoleOutcome::OperclassCleared,
            ['store:operclass', 'audit', 'network:operclass'],
            'ROLE OPERCLASS CLEAR',
            [],
        ];
    }

    #[Test]
    public function supportedOperclassWithoutEnumerableCatalogAllowsSetAndListsAsEmpty(): void
    {
        $trace = new RoleOperationTrace();
        $roles = new MemoryOperatorRoleStore($trace, ['ADMIN' => self::role()]);
        $network = new RecordingOperatorRoleNetworkProjection($trace, true, null);
        $audit = new RecordingRoleAudit($trace);
        $handler = $this->handler($roles, [], $network, [], true, $audit);

        $listed = $handler->handle($this->command(RoleAction::OperclassList));
        $set = $handler->handle($this->command(RoleAction::OperclassSet, 'admin', ' custom-oper '));

        self::assertTrue($handler->supportsOperclass());
        self::assertSame(RoleOutcome::OperclassesListed, $listed->outcome);
        self::assertSame([], $listed->values);
        self::assertSame(RoleOutcome::OperclassSet, $set->outcome);
        self::assertSame(['custom-oper'], $set->values);
        self::assertSame([['ADMIN', 'custom-oper']], $roles->operclassUpdates);
        self::assertSame([[7, 'custom-oper']], $network->operclassRefreshes);
        self::assertSame(['store:operclass', 'audit', 'network:operclass'], $trace->entries);
        self::assertCount(1, $audit->records);
        $this->assertAudit($audit->records[0], 'ROLE OPERCLASS SET', 'ADMIN', ['operclass' => 'custom-oper']);
    }

    /**
     * @param list<string>      $permissionCatalog
     * @param list<string>|null $modeCatalog
     * @param list<string>|null $operclassCatalog
     */
    #[Test]
    #[DataProvider('readActions')]
    public function readsAndUnknownActionsNeverAudit(
        RoleAction $action,
        string $roleName,
        array $permissionCatalog,
        ?array $modeCatalog,
        bool $supportsOperclass,
        ?array $operclassCatalog,
        RoleOutcome $expectedOutcome,
    ): void {
        $trace = new RoleOperationTrace();
        $roles = new MemoryOperatorRoleStore($trace, ['ADMIN' => self::role(permissions: ['operserv.global'], modes: ['o'], vhost: 'staff.test', operclass: 'NetAdmin')]);
        $audit = new RecordingRoleAudit($trace);

        $result = $this->handler(
            $roles,
            $permissionCatalog,
            new RecordingOperatorRoleNetworkProjection($trace, $supportsOperclass, $operclassCatalog),
            $modeCatalog,
            true,
            $audit,
        )->handle($this->command($action, $roleName));

        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame([], $trace->entries);
        self::assertSame([], $audit->records);
    }

    /** @return iterable<string, array{RoleAction, string, list<string>, list<string>|null, bool, list<string>|null, RoleOutcome}> */
    public static function readActions(): iterable
    {
        yield 'roles list' => [RoleAction::List, '', [], [], true, [], RoleOutcome::Listed];
        yield 'permission list' => [RoleAction::PermissionList, 'admin', ['operserv.global'], [], true, [], RoleOutcome::PermissionsListed];
        yield 'modes view' => [RoleAction::ModesView, 'admin', [], ['o'], true, [], RoleOutcome::ModesViewed];
        yield 'vhost view' => [RoleAction::VhostView, 'admin', [], [], true, [], RoleOutcome::VhostViewed];
        yield 'operclass list' => [RoleAction::OperclassList, '', [], [], true, ['NetAdmin'], RoleOutcome::OperclassesListed];
        yield 'operclass view' => [RoleAction::OperclassView, 'admin', [], [], true, ['NetAdmin'], RoleOutcome::OperclassViewed];
        yield 'unknown' => [RoleAction::Unknown, '', [], [], true, [], RoleOutcome::UnknownAction];
    }

    /**
     * @param list<string>      $permissionCatalog
     * @param list<string>|null $modeCatalog
     * @param list<string>|null $operclassCatalog
     */
    #[Test]
    #[DataProvider('rejectedAndNoOpActions')]
    public function rejectionsAndNoOpsNeverMutateProjectOrAudit(
        RoleAction $action,
        ?OperatorRoleRecord $existingRole,
        string $roleName,
        string $value,
        array $permissionCatalog,
        ?array $modeCatalog,
        bool $validVhost,
        bool $supportsOperclass,
        ?array $operclassCatalog,
        RoleOutcome $expectedOutcome,
    ): void {
        $trace = new RoleOperationTrace();
        $roles = new MemoryOperatorRoleStore($trace, null === $existingRole ? [] : [$existingRole->name => $existingRole]);
        $audit = new RecordingRoleAudit($trace);

        $result = $this->handler(
            $roles,
            $permissionCatalog,
            new RecordingOperatorRoleNetworkProjection($trace, $supportsOperclass, $operclassCatalog),
            $modeCatalog,
            $validVhost,
            $audit,
        )->handle($this->command($action, $roleName, $value));

        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame([], $trace->entries);
        self::assertSame([], $audit->records);
    }

    /**
     * @return iterable<string, array{
     *     RoleAction,
     *     OperatorRoleRecord|null,
     *     string,
     *     string,
     *     list<string>,
     *     list<string>|null,
     *     bool,
     *     bool,
     *     list<string>|null,
     *     RoleOutcome
     * }>
     */
    public static function rejectedAndNoOpActions(): iterable
    {
        yield 'add blank name' => [RoleAction::Add, null, ' ', '', [], [], true, false, null, RoleOutcome::InvalidRequest];
        yield 'add existing' => [RoleAction::Add, self::role(), 'admin', '', [], [], true, false, null, RoleOutcome::AlreadyExists];
        yield 'delete missing' => [RoleAction::Delete, null, 'missing', '', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'delete protected' => [RoleAction::Delete, self::role(protected: true), 'admin', '', [], [], true, false, null, RoleOutcome::Protected];
        yield 'permission list missing role' => [RoleAction::PermissionList, null, 'missing', '', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'permission add missing role' => [RoleAction::PermissionAdd, null, 'missing', 'operserv.kill', ['operserv.kill'], [], true, false, null, RoleOutcome::NotFound];
        yield 'permission add unknown' => [RoleAction::PermissionAdd, self::role(), 'admin', 'operserv.missing', ['operserv.kill'], [], true, false, null, RoleOutcome::PermissionNotFound];
        yield 'permission add duplicate' => [RoleAction::PermissionAdd, self::role(permissions: ['operserv.kill']), 'admin', 'operserv.kill', ['operserv.kill'], [], true, false, null, RoleOutcome::PermissionAlreadyAssigned];
        yield 'permission add all missing role' => [RoleAction::PermissionAddAll, null, 'missing', '', ['operserv.kill'], [], true, false, null, RoleOutcome::NotFound];
        yield 'permission add all no-op' => [RoleAction::PermissionAddAll, self::role(permissions: ['operserv.kill']), 'admin', '', ['operserv.kill'], [], true, false, null, RoleOutcome::PermissionAlreadyAssigned];
        yield 'permission delete missing role' => [RoleAction::PermissionDelete, null, 'missing', 'operserv.kill', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'permission delete absent' => [RoleAction::PermissionDelete, self::role(), 'admin', 'operserv.kill', [], [], true, false, null, RoleOutcome::PermissionMissing];
        yield 'permission delete protected' => [RoleAction::PermissionDelete, self::role(protected: true, permissions: ['operserv.kill']), 'admin', 'operserv.kill', [], [], true, false, null, RoleOutcome::Protected];
        yield 'permission clear missing role' => [RoleAction::PermissionClear, null, 'missing', '', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'permission clear empty' => [RoleAction::PermissionClear, self::role(), 'admin', '', [], [], true, false, null, RoleOutcome::PermissionsEmpty];
        yield 'modes view missing role' => [RoleAction::ModesView, null, 'missing', '', [], ['o'], true, false, null, RoleOutcome::NotFound];
        yield 'modes set missing role' => [RoleAction::ModesSet, null, 'missing', '+o', [], ['o'], true, false, null, RoleOutcome::NotFound];
        yield 'modes unsupported' => [RoleAction::ModesSet, self::role(), 'admin', '+o', [], null, true, false, null, RoleOutcome::ModesNotSupported];
        yield 'modes invalid' => [RoleAction::ModesSet, self::role(), 'admin', '+x', [], ['o'], true, false, null, RoleOutcome::InvalidModes];
        yield 'modes unchanged' => [RoleAction::ModesSet, self::role(modes: ['o', 's']), 'admin', '+so', [], ['o', 's'], true, false, null, RoleOutcome::ModesSet];
        yield 'modes clear no-op' => [RoleAction::ModesSet, self::role(), 'admin', '', [], ['o'], true, false, null, RoleOutcome::ModesCleared];
        yield 'vhost view missing role' => [RoleAction::VhostView, null, 'missing', '', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'vhost set missing role' => [RoleAction::VhostSet, null, 'missing', 'staff.test', [], [], true, false, null, RoleOutcome::NotFound];
        yield 'vhost invalid' => [RoleAction::VhostSet, self::role(), 'admin', 'invalid', [], [], false, false, null, RoleOutcome::InvalidVhost];
        yield 'vhost unchanged' => [RoleAction::VhostSet, self::role(vhost: 'staff.test'), 'admin', 'staff.test', [], [], true, false, null, RoleOutcome::VhostSet];
        yield 'vhost clear no-op' => [RoleAction::VhostSet, self::role(), 'admin', 'OFF', [], [], true, false, null, RoleOutcome::VhostCleared];
        yield 'operclass unsupported list' => [RoleAction::OperclassList, null, '', '', [], [], true, false, null, RoleOutcome::OperclassNotSupported];
        yield 'operclass view missing role' => [RoleAction::OperclassView, null, 'missing', '', [], [], true, true, ['NetAdmin'], RoleOutcome::NotFound];
        yield 'operclass set missing role' => [RoleAction::OperclassSet, null, 'missing', 'NetAdmin', [], [], true, true, ['NetAdmin'], RoleOutcome::NotFound];
        yield 'operclass unsupported set' => [RoleAction::OperclassSet, self::role(), 'admin', 'NetAdmin', [], [], true, false, null, RoleOutcome::OperclassNotSupported];
        yield 'operclass unavailable' => [RoleAction::OperclassSet, self::role(), 'admin', 'LocalAdmin', [], [], true, true, ['NetAdmin'], RoleOutcome::OperclassNotAvailable];
        yield 'operclass unchanged' => [RoleAction::OperclassSet, self::role(operclass: 'NetAdmin'), 'admin', 'netadmin', [], [], true, true, ['NetAdmin'], RoleOutcome::OperclassSet];
        yield 'operclass clear no-op' => [RoleAction::OperclassSet, self::role(), 'admin', 'OFF', [], [], true, true, ['NetAdmin'], RoleOutcome::OperclassCleared];
    }

    /**
     * @param list<string>      $permissions
     * @param list<string>|null $modes
     */
    private function handler(
        OperatorRoleStore $roles,
        array $permissions,
        OperatorRoleNetworkProjection $network,
        ?array $modes,
        bool $validVhost,
        CommandAuditRecorder $audit,
    ): ManageRoleHandler {
        return new ManageRoleHandler(
            $roles,
            new FixedOperatorPermissionCatalog($permissions),
            $network,
            new FixedOperatorModeCatalog($modes),
            new FixedForcedVhostPolicy($validVhost),
            $audit,
        );
    }

    private function command(RoleAction $action, string $roleName = '', string $value = '', string $description = ''): ManageRole
    {
        return new ManageRole($action, 'RootOper', $this->occurredAt, $roleName, $value, description: $description);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $modes
     */
    private static function role(
        bool $protected = false,
        array $permissions = [],
        array $modes = [],
        ?string $vhost = null,
        ?string $operclass = null,
    ): OperatorRoleRecord {
        return new OperatorRoleRecord(7, 'ADMIN', 'Administrators', $protected, $permissions, $modes, $vhost, $operclass);
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

final class RoleOperationTrace
{
    /** @var list<string> */
    public array $entries = [];
}

final class MemoryOperatorRoleStore implements OperatorRoleStore
{
    /** @var list<array{string, string|null}> */
    public array $operclassUpdates = [];

    /** @param array<string, OperatorRoleRecord> $roles */
    public function __construct(private readonly RoleOperationTrace $trace, private array $roles = []) {}

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
        $this->trace->entries[] = 'store:create';

        return $this->roles[$name] = new OperatorRoleRecord(99, $name, $description, false);
    }

    public function remove(string $name): void
    {
        $this->trace->entries[] = 'store:remove';
        unset($this->roles[$name]);
    }

    public function setPermissions(string $roleName, array $permissions): void
    {
        $this->trace->entries[] = 'store:permissions';
    }

    public function setUserModes(string $roleName, array $modes): void
    {
        $this->trace->entries[] = 'store:modes';
    }

    public function setForcedVhostPattern(string $roleName, ?string $pattern): void
    {
        $this->trace->entries[] = 'store:vhost';
    }

    public function setOperclass(string $roleName, ?string $operclass): void
    {
        $this->trace->entries[] = 'store:operclass';
        $this->operclassUpdates[] = [$roleName, $operclass];
    }
}

final readonly class FixedOperatorPermissionCatalog implements OperatorPermissionCatalog
{
    /** @param list<string> $permissions */
    public function __construct(private array $permissions) {}

    public function all(): array
    {
        return $this->permissions;
    }
}

final readonly class FixedOperatorModeCatalog implements OperatorModeCatalog
{
    /** @param list<string>|null $modes */
    public function __construct(private ?array $modes) {}

    public function available(): ?array
    {
        return $this->modes;
    }
}

final readonly class FixedForcedVhostPolicy implements ForcedVhostPolicy
{
    public function __construct(private bool $valid) {}

    public function isValid(string $pattern): bool
    {
        return $this->valid;
    }
}

final class RecordingOperatorRoleNetworkProjection implements OperatorRoleNetworkProjection
{
    /** @var list<array{int, string|null}> */
    public array $operclassRefreshes = [];

    /** @param list<string>|null $availableOperclasses */
    public function __construct(
        private readonly RoleOperationTrace $trace,
        private readonly bool $supportsOperclass,
        private readonly ?array $availableOperclasses,
    ) {}

    public function supportsOperclass(): bool
    {
        return $this->supportsOperclass;
    }

    public function refreshModes(int $roleId, array $oldModes, array $newModes): void
    {
        $this->trace->entries[] = 'network:modes';
    }

    public function refreshVhost(int $roleId, ?string $pattern): void
    {
        $this->trace->entries[] = 'network:vhost';
    }

    public function refreshOperclass(int $roleId, ?string $operclass): void
    {
        $this->trace->entries[] = 'network:operclass';
        $this->operclassRefreshes[] = [$roleId, $operclass];
    }

    public function availableOperclasses(): ?array
    {
        return $this->availableOperclasses;
    }
}

final class RecordingRoleAudit implements CommandAuditRecorder
{
    /** @var list<CommandAuditRecord> */
    public array $records = [];

    public function __construct(private readonly RoleOperationTrace $trace) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->trace->entries[] = 'audit';
        $this->records[] = $record;
    }
}
