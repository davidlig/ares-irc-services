<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\Port\Out\OperatorRoleStore;
use LogicException;

final readonly class LegacyOperatorRoleStore implements OperatorRoleStore
{
    public function __construct(private OperRoleRepositoryInterface $roles, private OperPermissionRepositoryInterface $permissions) {}

    public function findByName(string $name): ?OperatorRoleRecord
    {
        $role = $this->roles->findByName($name);

        return null === $role ? null : $this->record($role);
    }

    public function all(): array
    {
        return array_values(array_map($this->record(...), $this->roles->findAll()));
    }

    public function create(string $name, string $description): OperatorRoleRecord
    {
        $role = OperRole::create($name, $description, false);
        $this->roles->save($role);

        return $this->record($role);
    }

    public function remove(string $name): void
    {
        $role = $this->roles->findByName($name);
        if (null !== $role) {
            $this->roles->remove($role);
        }
    }

    /** @param list<string> $permissions */
    public function setPermissions(string $roleName, array $permissions): void
    {
        $role = $this->require($roleName);
        foreach ($role->getPermissions() as $p) {
            $role->removePermission($p);
        }foreach ($permissions as $name) {
            $p = $this->permissions->findByName($name);
            if (null === $p) {
                $p = OperPermission::create($name);
                $this->permissions->save($p);
            }
            $role->addPermission($p);
        }$this->roles->save($role);
    }

    public function setUserModes(string $roleName, array $modes): void
    {
        $role = $this->require($roleName);
        $role->changeUserModes($modes);
        $this->roles->save($role);
    }

    public function setForcedVhostPattern(string $roleName, ?string $pattern): void
    {
        $role = $this->require($roleName);
        $role->changeForcedVhostPattern($pattern);
        $this->roles->save($role);
    }

    public function setOperclass(string $roleName, ?string $operclass): void
    {
        $role = $this->require($roleName);
        $role->changeOperclass($operclass);
        $this->roles->save($role);
    }

    private function require(string $name): OperRole
    {
        return $this->roles->findByName($name) ?? throw new LogicException('Role disappeared during mutation.');
    }

    private function record(OperRole $role): OperatorRoleRecord
    {
        return new OperatorRoleRecord($role->getId(), $role->getName(), $role->getDescription(), $role->isProtected(), array_map(static fn (OperPermission $p): string => $p->getName(), $role->getPermissions()), $role->getUserModes(), $role->getForcedVhostPattern(), $role->getOperclass());
    }
}
