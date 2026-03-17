<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\AdminAccessHelper;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
use function sprintf;
use function strtoupper;

final readonly class RoleCommand implements OperServCommandInterface
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private OperPermissionRepositoryInterface $permissionRepository,
        private AdminAccessHelper $accessHelper,
    ) {
    }

    public function getName(): string
    {
        return 'ROLE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'role.syntax';
    }

    public function getHelpKey(): string
    {
        return 'role.help';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'role.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'LIST', 'desc_key' => 'role.list.short', 'help_key' => 'role.list.help', 'syntax_key' => 'role.list.syntax'],
            ['name' => 'ADD', 'desc_key' => 'role.add.short', 'help_key' => 'role.add.help', 'syntax_key' => 'role.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'role.del.short', 'help_key' => 'role.del.help', 'syntax_key' => 'role.del.syntax'],
            ['name' => 'PERMS', 'desc_key' => 'role.perms.short', 'help_key' => 'role.perms.help', 'syntax_key' => 'role.perms.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function execute(OperServContext $context): void
    {
        if (!$context->isRoot()) {
            $context->reply('error.root_only');

            return;
        }

        $sub = strtoupper($context->args[0] ?? '');

        switch ($sub) {
            case 'ADD':
                $this->doAdd($context);
                break;
            case 'DEL':
                $this->doDel($context);
                break;
            case 'LIST':
                $this->doList($context);
                break;
            case 'PERMS':
                $this->doPerms($context);
                break;
            default:
                $context->reply('role.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doAdd(OperServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.add.syntax')]);

            return;
        }

        $name = strtoupper($context->args[1]);
        $description = $context->args[2] ?? '';

        if ('' === $description) {
            $description = 'Custom role';
        }

        $existing = $this->roleRepository->findByName($name);
        if (null !== $existing) {
            $context->reply('role.already_exists', ['%role%' => $name]);

            return;
        }

        $role = OperRole::create($name, $description, false);
        $this->roleRepository->save($role);

        $context->reply('role.add.done', ['%role%' => $name]);
    }

    private function doDel(OperServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.del.syntax')]);

            return;
        }

        $name = strtoupper($context->args[1]);

        $role = $this->roleRepository->findByName($name);
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => $name]);

            return;
        }

        if ($role->isProtected()) {
            $context->reply('role.protected', ['%role%' => $name]);

            return;
        }

        $this->roleRepository->remove($role);
        $context->reply('role.del.done', ['%role%' => $name]);
    }

    private function doList(OperServContext $context): void
    {
        $roles = $this->roleRepository->findAll();

        if ([] === $roles) {
            $context->reply('role.list.empty');

            return;
        }

        $context->reply('role.list.header');

        foreach ($roles as $role) {
            $protected = $role->isProtected() ? ' [PROTECTED]' : '';
            $context->replyRaw(sprintf('  %-12s%s', $role->getName(), $protected));
        }
    }

    private function doPerms(OperServContext $context): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.syntax')]);

            return;
        }

        $roleName = strtoupper($context->args[1]);
        $action = strtoupper($context->args[2]);

        $role = $this->roleRepository->findByName($roleName);
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => $roleName]);

            return;
        }

        switch ($action) {
            case 'LIST':
                $this->listPerms($context, $role);
                break;
            case 'ADD':
                $this->addPerm($context, $role);
                break;
            case 'DEL':
                $this->delPerm($context, $role);
                break;
            default:
                $context->reply('role.perms.unknown_action', ['%action%' => $action]);
        }
    }

    private function listPerms(OperServContext $context, OperRole $role): void
    {
        $permissions = $role->getPermissions();

        if ($permissions->isEmpty()) {
            $context->reply('role.perms.list.empty', ['%role%' => $role->getName()]);

            return;
        }

        $context->reply('role.perms.list.header', ['%role%' => $role->getName()]);

        foreach ($permissions as $permission) {
            $context->replyRaw(sprintf('  %s', $permission->getName()));
        }
    }

    private function addPerm(OperServContext $context, OperRole $role): void
    {
        if (count($context->args) < 4) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.add.syntax')]);

            return;
        }

        $permName = $context->args[3];

        $permission = $this->permissionRepository->findByName($permName);
        if (null === $permission) {
            $context->reply('role.perms.not_found', ['%perm%' => $permName]);

            return;
        }

        if ($role->hasPermission($permName)) {
            $context->reply('role.perms.already_has', ['%role%' => $role->getName(), '%perm%' => $permName]);

            return;
        }

        $role->addPermission($permission);
        $this->roleRepository->save($role);

        $context->reply('role.perms.add.done', ['%role%' => $role->getName(), '%perm%' => $permName]);
    }

    private function delPerm(OperServContext $context, OperRole $role): void
    {
        if (count($context->args) < 4) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.perms.del.syntax')]);

            return;
        }

        $permName = $context->args[3];

        $permission = $this->permissionRepository->findByName($permName);
        if (null === $permission) {
            $context->reply('role.perms.not_found', ['%perm%' => $permName]);

            return;
        }

        if (!$role->hasPermission($permName)) {
            $context->reply('role.perms.does_not_have', ['%role%' => $role->getName(), '%perm%' => $permName]);

            return;
        }

        if ($role->isProtected()) {
            $context->reply('role.perms.protected', ['%role%' => $role->getName()]);

            return;
        }

        $role->removePermission($permission);
        $this->roleRepository->save($role);

        $context->reply('role.perms.del.done', ['%role%' => $role->getName(), '%perm%' => $permName]);
    }
}
