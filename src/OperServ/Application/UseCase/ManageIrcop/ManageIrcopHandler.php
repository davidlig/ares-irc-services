<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorRoleStore;

final readonly class ManageIrcopHandler implements ManageIrcopHandlerInterface
{
    public function __construct(private OperatorAccountLookup $accounts, private OperatorAssignmentStore $assignments, private OperatorRoleStore $roles, private OperatorAssignmentNetworkProjection $network) {}

    public function handle(ManageIrcop $command): ManageIrcopResult
    {
        return match ($command->action) {
            IrcopAction::Add => $this->add($command),IrcopAction::Delete => $this->delete($command->nickname),IrcopAction::List => $this->list(),IrcopAction::Unknown => new ManageIrcopResult(IrcopOutcome::UnknownAction)
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
        }if (null !== $old) {
            $this->network->remove($account->id, $c->nickname, $old->role);
        }$this->assignments->assign($account->id, $role, $c->actorAccountId);
        $this->network->apply($account->id, $c->nickname, $role);

        return new ManageIrcopResult(null === $old ? IrcopOutcome::Added : IrcopOutcome::RoleChanged, $c->nickname, $role->name, $old?->role->name);
    }

    private function delete(string $nickname): ManageIrcopResult
    {
        $account = $this->accounts->findByNickname($nickname);
        if (null === $account) {
            return new ManageIrcopResult(IrcopOutcome::NickNotRegistered, nickname: $nickname);
        }$assignment = $this->assignments->findByNickId($account->id);
        if (null === $assignment) {
            return new ManageIrcopResult(IrcopOutcome::NotAssigned, nickname: $nickname);
        }$this->network->remove($account->id, $nickname, $assignment->role);
        $this->assignments->remove($account->id);

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
}
