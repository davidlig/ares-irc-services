<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

use App\OperServ\Application\Port\Out\ForcedVhostPolicy;
use App\OperServ\Application\Port\Out\OperatorModeCatalog;
use App\OperServ\Application\Port\Out\OperatorPermissionCatalog;
use App\OperServ\Application\Port\Out\OperatorRoleNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\Port\Out\OperatorRoleStore;

use function array_diff;
use function array_values;
use function count;
use function in_array;
use function strcasecmp;
use function strtoupper;
use function trim;

final readonly class ManageRoleHandler implements ManageRoleHandlerInterface
{
    public function __construct(
        private OperatorRoleStore $roles,
        private OperatorPermissionCatalog $permissions,
        private OperatorRoleNetworkProjection $network,
        private OperatorModeCatalog $modes,
        private ForcedVhostPolicy $vhostPolicy,
    ) {}

    public function handle(ManageRole $command): ManageRoleResult
    {
        $name = strtoupper(trim($command->roleName));

        return match ($command->action) {
            RoleAction::Add => $this->add($name, $command->description),
            RoleAction::Delete => $this->delete($name),
            RoleAction::List => new ManageRoleResult(RoleOutcome::Listed, roles: $this->roles->all()),
            RoleAction::PermissionList => $this->permissionList($name),
            RoleAction::PermissionAdd => $this->permissionAdd($name, $command->value),
            RoleAction::PermissionAddAll => $this->permissionAddAll($name),
            RoleAction::PermissionDelete => $this->permissionDelete($name, $command->value),
            RoleAction::PermissionClear => $this->permissionClear($name),
            RoleAction::ModesView => $this->modesView($name),
            RoleAction::ModesSet => $this->modesSet($name, $command->value),
            RoleAction::VhostView => $this->vhostView($name),
            RoleAction::VhostSet => $this->vhostSet($name, $command->value),
            RoleAction::OperclassList => $this->operclassList(),
            RoleAction::OperclassView => $this->operclassView($name),
            RoleAction::OperclassSet => $this->operclassSet($name, $command->value),
            RoleAction::Unknown => new ManageRoleResult(RoleOutcome::UnknownAction),
        };
    }

    private function add(string $name, string $description): ManageRoleResult
    {
        if ('' === $name) {
            return new ManageRoleResult(RoleOutcome::InvalidRequest);
        }
        if (null !== $this->roles->findByName($name)) {
            return new ManageRoleResult(RoleOutcome::AlreadyExists);
        }

        return new ManageRoleResult(RoleOutcome::Added, $this->roles->create($name, '' === trim($description) ? 'Custom role' : $description));
    }

    private function delete(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        if ($role->protected) {
            return new ManageRoleResult(RoleOutcome::Protected, $role);
        }
        $this->roles->remove($name);

        return new ManageRoleResult(RoleOutcome::Deleted, $role);
    }

    private function permissionList(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }

        return new ManageRoleResult(RoleOutcome::PermissionsListed, $role, values: $role->permissions, availableValues: array_values(array_diff($this->permissions->all(), $role->permissions)));
    }

    private function permissionAdd(string $name, string $permission): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        if (!in_array($permission, $this->permissions->all(), true)) {
            return new ManageRoleResult(RoleOutcome::PermissionNotFound, $role);
        }
        if (in_array($permission, $role->permissions, true)) {
            return new ManageRoleResult(RoleOutcome::PermissionAlreadyAssigned, $role);
        }
        $new = [...$role->permissions, $permission];
        $this->roles->setPermissions($role->name, $new);

        return new ManageRoleResult(RoleOutcome::PermissionAdded, $this->withPermissions($role, $new), values: [$permission]);
    }

    private function permissionAddAll(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        $new = $this->permissions->all();
        $added = array_values(array_diff($new, $role->permissions));
        if ([] === $added) {
            return new ManageRoleResult(RoleOutcome::PermissionAlreadyAssigned, $role);
        }
        $this->roles->setPermissions($role->name, $new);

        return new ManageRoleResult(RoleOutcome::PermissionAddedAll, $this->withPermissions($role, $new), values: $added, count: count($added));
    }

    private function permissionDelete(string $name, string $permission): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        if (!in_array($permission, $role->permissions, true)) {
            return new ManageRoleResult(RoleOutcome::PermissionMissing, $role);
        }
        if ($role->protected) {
            return new ManageRoleResult(RoleOutcome::Protected, $role);
        }
        $new = array_values(array_diff($role->permissions, [$permission]));
        $this->roles->setPermissions($role->name, $new);

        return new ManageRoleResult(RoleOutcome::PermissionRemoved, $this->withPermissions($role, $new), values: [$permission]);
    }

    private function permissionClear(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        if ([] === $role->permissions) {
            return new ManageRoleResult(RoleOutcome::PermissionsEmpty, $role);
        }
        $count = count($role->permissions);
        $this->roles->setPermissions($role->name, []);

        return new ManageRoleResult(RoleOutcome::PermissionsCleared, $this->withPermissions($role, []), count: $count);
    }

    private function modesView(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }

        return new ManageRoleResult(RoleOutcome::ModesViewed, $role, values: $role->userModes);
    }

    private function modesSet(string $name, string $value): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        $available = $this->modes->available();
        if (null === $available) {
            return new ManageRoleResult(RoleOutcome::ModesNotSupported, $role);
        }
        $modes = array_values(array_unique(str_split(ltrim(trim($value), '+'))));
        $invalid = array_values(array_diff($modes, $available));
        if ([] !== $invalid) {
            return new ManageRoleResult(RoleOutcome::InvalidModes, $role, values: $invalid, availableValues: $available);
        }
        $this->roles->setUserModes($role->name, $modes);
        $this->network->refreshModes($role->id, $role->userModes, $modes);

        return new ManageRoleResult([] === $modes ? RoleOutcome::ModesCleared : RoleOutcome::ModesSet, $this->withModes($role, $modes), values: $modes);
    }

    private function vhostView(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);

        return null === $role ? new ManageRoleResult(RoleOutcome::NotFound) : new ManageRoleResult(RoleOutcome::VhostViewed, $role, values: null === $role->forcedVhostPattern ? [] : [$role->forcedVhostPattern]);
    }

    private function vhostSet(string $name, string $value): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }
        $pattern = trim($value);
        if ('' === $pattern || 'OFF' === strtoupper($pattern)) {
            $this->roles->setForcedVhostPattern($role->name, null);
            $this->network->refreshVhost($role->id, null);

            return new ManageRoleResult(RoleOutcome::VhostCleared, $role);
        }
        if (!$this->vhostPolicy->isValid($pattern)) {
            return new ManageRoleResult(RoleOutcome::InvalidVhost, $role);
        }
        $this->roles->setForcedVhostPattern($role->name, $pattern);
        $this->network->refreshVhost($role->id, $pattern);

        return new ManageRoleResult(RoleOutcome::VhostSet, $role, values: [$pattern]);
    }

    private function operclassList(): ManageRoleResult
    {
        $available = $this->network->availableOperclasses();

        return null === $available ? new ManageRoleResult(RoleOutcome::OperclassNotSupported) : new ManageRoleResult(RoleOutcome::OperclassesListed, values: $available);
    }

    private function operclassView(string $name): ManageRoleResult
    {
        $role = $this->roles->findByName($name);

        return null === $role ? new ManageRoleResult(RoleOutcome::NotFound) : new ManageRoleResult(RoleOutcome::OperclassViewed, $role, values: null === $role->operclass ? [] : [$role->operclass], availableValues: $this->network->availableOperclasses() ?? []);
    }

    private function operclassSet(string $name, string $value): ManageRoleResult
    {
        $role = $this->roles->findByName($name);
        if (null === $role) {
            return new ManageRoleResult(RoleOutcome::NotFound);
        }$available = $this->network->availableOperclasses();
        if (null === $available) {
            return new ManageRoleResult(RoleOutcome::OperclassNotSupported, $role);
        }$operclass = trim($value);
        if ('' === $operclass || 'OFF' === strtoupper($operclass)) {
            $this->roles->setOperclass($role->name, null);
            $this->network->refreshOperclass($role->id, null);

            return new ManageRoleResult(RoleOutcome::OperclassCleared, $role);
        }foreach ($available as $candidate) {
            if (0 === strcasecmp($candidate, $operclass)) {
                $this->roles->setOperclass($role->name, $candidate);
                $this->network->refreshOperclass($role->id, $candidate);

                return new ManageRoleResult(RoleOutcome::OperclassSet, $role, values: [$candidate]);
            }
        }

        return new ManageRoleResult(RoleOutcome::OperclassNotAvailable, $role, availableValues: $available);
    }

    /** @param list<string> $p */
    private function withPermissions(OperatorRoleRecord $r, array $p): OperatorRoleRecord
    {
        return new OperatorRoleRecord($r->id, $r->name, $r->description, $r->protected, $p, $r->userModes, $r->forcedVhostPattern, $r->operclass);
    }

    /** @param list<string> $m */
    private function withModes(OperatorRoleRecord $r, array $m): OperatorRoleRecord
    {
        return new OperatorRoleRecord($r->id, $r->name, $r->description, $r->protected, $r->permissions, $m, $r->forcedVhostPattern, $r->operclass);
    }
}
