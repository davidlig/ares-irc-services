<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\Out\OperatorAssignmentRecord;
use App\OperServ\Application\Port\Out\OperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\Repository\OperRoleRepositoryInterface;
use DateTimeImmutable;
use LogicException;

final readonly class DoctrineOperatorAssignmentStore implements OperatorAssignmentStore
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

    public function assign(int $id, OperatorRoleRecord $role, ?int $by, DateTimeImmutable $addedAt): void
    {
        $legacy = $this->role($role->name);
        $current = $this->assignments->findByNickId($id);
        if (null === $current) {
            $this->assignments->save(OperIrcop::create($addedAt, $id, $legacy, $by));
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
