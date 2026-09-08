<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Application\Port\Out\OperatorAssignmentRecord;
use App\OperServ\Application\Port\Out\OperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use LogicException;

final readonly class LegacyOperatorAssignmentStore implements OperatorAssignmentStore
{
    public function __construct(private OperIrcopRepositoryInterface $assignments, private OperRoleRepositoryInterface $roles) {}

    public function findByNickId(int $id): ?OperatorAssignmentRecord
    {
        $a = $this->assignments->findByNickId($id);

        return null === $a ? null : $this->record($a);
    }

    public function all(): array
    {
        return array_values(array_map($this->record(...), $this->assignments->findAll()));
    }

    public function assign(int $id, OperatorRoleRecord $role, ?int $by): void
    {
        $legacy = $this->role($role->name);
        $current = $this->assignments->findByNickId($id);
        if (null === $current) {
            $this->assignments->save(OperIrcop::create($id, $legacy, $by));
        } else {
            $current->changeRole($legacy);
            $this->assignments->save($current);
        }
    }

    public function remove(int $id): void
    {
        $current = $this->assignments->findByNickId($id);
        if (null !== $current) {
            $this->assignments->remove($current);
        }
    }

    private function role(string $name): OperRole
    {
        return $this->roles->findByName($name) ?? throw new LogicException('Role disappeared during assignment.');
    }

    private function record(OperIrcop $a): OperatorAssignmentRecord
    {
        $r = $a->getRole();

        return new OperatorAssignmentRecord($a->getNickId(), new OperatorRoleRecord($r->getId(), $r->getName(), $r->getDescription(), $r->isProtected(), array_map(static fn (OperPermission $permission): string => $permission->getName(), $r->getPermissions()), $r->getUserModes(), $r->getForcedVhostPattern(), $r->getOperclass()), $a->getAddedAt());
    }
}
