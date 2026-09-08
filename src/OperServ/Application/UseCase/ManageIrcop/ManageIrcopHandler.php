<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorRoleStore;

final readonly class ManageIrcopHandler implements ManageIrcopHandlerInterface
{
    public function __construct(
        private OperatorAccountLookup $accounts,
        private OperatorAssignmentStore $assignments,
        private OperatorRoleStore $roles,
        private OperatorAssignmentNetworkProjection $network,
        private CommandAuditRecorder $audit,
    ) {}

    public function handle(ManageIrcop $command): ManageIrcopResult
    {
        return match ($command->action) {
            IrcopAction::Add => $this->add($command),
            IrcopAction::Delete => $this->delete($command),
            IrcopAction::List => $this->list(),
            IrcopAction::Unknown => new ManageIrcopResult(IrcopOutcome::UnknownAction),
        };
    }

    private function add(ManageIrcop $c): ManageIrcopResult
    {
        if ('' === $c->nickname || '' === $c->roleName) {
            return new ManageIrcopResult(IrcopOutcome::InvalidRequest);
        }$account = $this->accounts->findByNickname($c->nickname);
        if (null === $account) {
            return new ManageIrcopResult(IrcopOutcome::NickNotRegistered, nickname: $c->nickname);
        }if (!$account->registered) {
            return new ManageIrcopResult(IrcopOutcome::NickNotActive, nickname: $c->nickname);
        }$role = $this->roles->findByName($c->roleName);
        if (null === $role) {
            return new ManageIrcopResult(IrcopOutcome::RoleNotFound, nickname: $c->nickname, role: $c->roleName);
        }$old = $this->assignments->findByNickId($account->id);
        if (null !== $old && $old->role->name === $role->name) {
            return new ManageIrcopResult(IrcopOutcome::AlreadyAssigned, $c->nickname, $role->name);
        }$this->assignments->assign($account->id, $role, $c->actorAccountId);
        $outcome = null === $old ? IrcopOutcome::Added : IrcopOutcome::RoleChanged;
        $this->recordAudit(
            $c,
            IrcopOutcome::Added === $outcome ? 'IRCOP ADD' : 'IRCOP ROLE CHANGE',
            $c->nickname,
            null === $old ? ['role' => $role->name] : ['old_role' => $old->role->name, 'new_role' => $role->name],
        );
        null === $old
            ? $this->network->apply($account->id, $c->nickname, $role)
            : $this->network->replace($account->id, $c->nickname, $old->role, $role);

        return new ManageIrcopResult($outcome, $c->nickname, $role->name, $old?->role->name);
    }

    private function delete(ManageIrcop $command): ManageIrcopResult
    {
        $nickname = $command->nickname;
        $account = $this->accounts->findByNickname($nickname);
        if (null === $account) {
            return new ManageIrcopResult(IrcopOutcome::NickNotRegistered, nickname: $nickname);
        }$assignment = $this->assignments->findByNickId($account->id);
        if (null === $assignment) {
            return new ManageIrcopResult(IrcopOutcome::NotAssigned, nickname: $nickname);
        }$this->assignments->remove($account->id);
        $this->recordAudit($command, 'IRCOP DEL', $nickname, ['role' => $assignment->role->name]);
        $this->network->remove($account->id, $nickname, $assignment->role);

        return new ManageIrcopResult(IrcopOutcome::Deleted, $nickname, $assignment->role->name);
    }

    private function list(): ManageIrcopResult
    {
        $entries = [];
        foreach ($this->assignments->all() as $assignment) {
            $nick = $this->accounts->findNicknameById($assignment->nickId) ?? (string) $assignment->nickId;
            $entries[] = new IrcopListEntry($nick, $assignment->role->name, $assignment->addedAt);
        }

        return new ManageIrcopResult(IrcopOutcome::Listed, entries: $entries);
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function recordAudit(ManageIrcop $command, string $operation, string $target, array $metadata): void
    {
        $this->audit->record(new CommandAuditRecord(
            category: CommandAuditCategory::RootAdministration,
            service: 'OperServ',
            actor: $command->actorNickname,
            operation: $operation,
            occurredAt: $command->occurredAt,
            target: $target,
            permission: OperatorAuthorizationAttribute::ROOT,
            metadata: $metadata,
        ));
    }
}
