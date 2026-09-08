<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\EventBusInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Application\Port\Out\OperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\PublishedEvent\OperIrcopChangedEvent;
use LogicException;

final readonly class LegacyOperatorAssignmentNetworkProjection implements OperatorAssignmentNetworkProjection
{
    public function __construct(private IrcopModeApplier $modes, private IrcopOperclassApplier $operclass, private OperRoleRepositoryInterface $roles, private EventBusInterface $events) {}

    public function apply(int $nickId, string $nick, OperatorRoleRecord $role): void
    {
        $r = $this->role($role->name);
        $this->modes->applyModesForNick($nick, $r);
        $this->operclass->applyForNick($nick, $r);
        $this->events->dispatch(new OperIrcopChangedEvent($nickId, $nick));
    }

    public function remove(int $nickId, string $nick, OperatorRoleRecord $role): void
    {
        $r = $this->role($role->name);
        $this->modes->removeModesForNick($nick, $r);
        $this->operclass->removeForNick($nick);
        $this->events->dispatch(new OperIrcopChangedEvent($nickId, $nick));
    }

    public function replace(int $nickId, string $nick, OperatorRoleRecord $oldRole, OperatorRoleRecord $newRole): void
    {
        $old = $this->role($oldRole->name);
        $new = $this->role($newRole->name);
        $this->modes->removeModesForNick($nick, $old);
        $this->operclass->removeForNick($nick);
        $this->modes->applyModesForNick($nick, $new);
        $this->operclass->applyForNick($nick, $new);
        $this->events->dispatch(new OperIrcopChangedEvent($nickId, $nick));
    }

    private function role(string $name): OperRole
    {
        return $this->roles->findByName($name) ?? throw new LogicException('Role disappeared during projection.');
    }
}
