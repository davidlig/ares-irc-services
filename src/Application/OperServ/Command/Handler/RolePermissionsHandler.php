<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServContext;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function array_diff;
use function count;
use function in_array;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strstr;
use function strtoupper;

final readonly class RolePermissionsHandler
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private OperPermissionRepositoryInterface $permissionRepository,
        private PermissionRegistry $permissionRegistry,
    ) {}

    public function handle(OperServContext $context): CommandOutcome
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.syntax')]);

            return CommandOutcome::rejected();
        }

        $roleName = strtoupper($context->args[1]);
        $action = strtoupper($context->args[2]);

        $role = $this->roleRepository->findByName($roleName);
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => $roleName]);

            return CommandOutcome::rejected();
        }

        return match ($action) {
            'LIST' => $this->listAndReject($context, $role),
            'ADD' => $this->add($context, $role),
            'DEL' => $this->remove($context, $role),
            'CLEAR' => $this->clear($context, $role),
            default => $this->rejectUnknownAction($context, $action),
        };
    }

    private function listAndReject(OperServContext $context, OperRole $role): CommandOutcome
    {
        $this->list($context, $role);

        return CommandOutcome::rejected();
    }

    private function rejectUnknownAction(OperServContext $context, string $action): CommandOutcome
    {
        $context->reply('role.perms.unknown_action', ['%action%' => $action]);

        return CommandOutcome::rejected();
    }

    private function list(OperServContext $context, OperRole $role): void
    {
        $assignedPermissions = [];
        foreach ($role->getPermissions() as $permission) {
            $assignedPermissions[] = $permission->getName();
        }

        $availablePermissions = array_values(array_diff($this->permissionRegistry->getAllPermissions(), $assignedPermissions));

        if ([] === $assignedPermissions && [] === $availablePermissions) {
            $context->reply('role.perms.list.empty', ['%role%' => $role->getName()]);

            return;
        }

        $context->reply('role.perms.list.header', ['%role%' => $role->getName()]);

        if ([] !== $assignedPermissions) {
            $context->reply('role.perms.list.assigned');
            $this->listPermissionNames($context, $assignedPermissions);
        } else {
            $context->reply('role.perms.list.none_assigned');
        }

        if ([] !== $availablePermissions) {
            $context->reply('role.perms.list.available');
            $this->listPermissionNames($context, $availablePermissions);
        } else {
            $context->reply('role.perms.list.all_assigned');
        }
    }

    /** @param list<string> $permissions */
    private function listPermissionNames(OperServContext $context, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $description = $this->resolveDescription($permission, $context);
            $context->replyRaw(str_starts_with($description, 'permissions.')
                ? sprintf('  %s', $permission)
                : sprintf('  %s - %s', $permission, $description));
        }
    }

    private function add(OperServContext $context, OperRole $role): CommandOutcome
    {
        if (count($context->args) < 4) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.add.syntax')]);

            return CommandOutcome::rejected();
        }

        $permissionName = $context->args[3];
        if ('ALL' === strtoupper($permissionName)) {
            return $this->addAll($context, $role);
        }

        $permission = $this->findOrCreate($permissionName);
        $resultKey = null === $permission
            ? 'role.perms.not_found'
            : ($role->hasPermission($permissionName) ? 'role.perms.already_has' : null);

        if (null !== $resultKey) {
            $context->reply($resultKey, 'role.perms.not_found' === $resultKey
                ? ['%perm%' => $permissionName]
                : ['%role%' => $role->getName(), '%perm%' => $permissionName]);

            return CommandOutcome::rejected();
        }

        $role->addPermission($permission);
        $this->roleRepository->save($role);

        $context->reply('role.perms.add.done', ['%role%' => $role->getName(), '%perm%' => $permissionName]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => 'PERMISSION_ADD', 'permission' => $permissionName],
        ));
    }

    private function addAll(OperServContext $context, OperRole $role): CommandOutcome
    {
        $added = 0;
        $skipped = 0;

        foreach ($this->permissionRegistry->getAllPermissions() as $permissionName) {
            if ($role->hasPermission($permissionName)) {
                ++$skipped;

                continue;
            }

            $perm = $this->findOrCreate($permissionName);
            if (null === $perm) {
                continue;
            }

            $role->addPermission($perm);
            ++$added;
        }

        if ($added > 0) {
            $this->roleRepository->save($role);
        }

        if (0 === $added && $skipped > 0) {
            $context->reply('role.perms.add.all_skipped', ['%role%' => $role->getName()]);

            return CommandOutcome::rejected();
        }

        if (0 === $added) {
            $context->reply('role.perms.add.all_empty');

            return CommandOutcome::rejected();
        }

        $context->reply('role.perms.add.all_done', ['%role%' => $role->getName(), '%count%' => (string) $added]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => 'PERMISSION_ADD_ALL', 'count' => $added],
        ));
    }

    private function findOrCreate(string $permissionName): ?OperPermission
    {
        $permission = $this->permissionRepository->findByName($permissionName);
        if (null !== $permission) {
            return $permission;
        }

        if (!in_array($permissionName, $this->permissionRegistry->getAllPermissions(), true)) {
            return null;
        }

        $permission = OperPermission::create($permissionName);
        $this->permissionRepository->save($permission);

        return $permission;
    }

    private function clear(OperServContext $context, OperRole $role): CommandOutcome
    {
        $permissions = $role->getPermissions();
        if ([] === $permissions) {
            $context->reply('role.perms.clear.empty', ['%role%' => $role->getName()]);

            return CommandOutcome::rejected();
        }

        foreach ($permissions as $permission) {
            $role->removePermission($permission);
        }

        $this->roleRepository->save($role);

        $context->reply('role.perms.clear.done', ['%role%' => $role->getName(), '%count%' => (string) count($permissions)]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => 'PERMISSION_CLEAR', 'count' => count($permissions)],
        ));
    }

    private function remove(OperServContext $context, OperRole $role): CommandOutcome
    {
        if (count($context->args) < 4) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $permissionName = $context->args[3];
        $permission = $this->permissionRepository->findByName($permissionName);
        if (null === $permission) {
            $context->reply('role.perms.not_found', ['%perm%' => $permissionName]);

            return CommandOutcome::rejected();
        }

        $errorKey = !$role->hasPermission($permissionName)
            ? 'role.perms.does_not_have'
            : ($role->isProtected() ? 'role.perms.protected' : null);
        if (null !== $errorKey) {
            $context->reply($errorKey, ['%role%' => $role->getName(), '%perm%' => $permissionName]);

            return CommandOutcome::rejected();
        }

        $role->removePermission($permission);
        $this->roleRepository->save($role);

        $context->reply('role.perms.del.done', ['%role%' => $role->getName(), '%perm%' => $permissionName]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => 'PERMISSION_DEL', 'permission' => $permissionName],
        ));
    }

    private function resolveDescription(string $permission, OperServContext $context): string
    {
        $domain = str_contains($permission, '.') ? (strstr($permission, '.', true) ?: 'operserv') : 'operserv';
        $description = $context->transForDomain('permissions.' . $permission, $domain);

        if (str_starts_with($description, 'permissions.') && 'operserv' !== $domain) {
            return $context->trans('permissions.' . $permission);
        }

        return $description;
    }
}
