<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
use function sprintf;
use function strtoupper;

final readonly class RoleCommand implements OperServCommandInterface
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private RolePermissionsHandler $permissions,
        private RoleOperclassHandler $operclass,
        private RoleModesHandler $modes,
        private RoleVhostHandler $vhost,
        private IrcopAccessHelper $accessHelper,
    ) {}

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
        return $this->operclass->isSupported() ? 'role.syntax_operclass' : 'role.syntax';
    }

    public function getHelpKey(): string
    {
        return $this->operclass->isSupported() ? 'role.help_operclass' : 'role.help';
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
            ['name' => 'MODES', 'desc_key' => 'role.modes.short', 'help_key' => 'role.modes.help', 'syntax_key' => 'role.modes.syntax'],
            ['name' => 'VHOST', 'desc_key' => 'role.vhost.short', 'help_key' => 'role.vhost.help', 'syntax_key' => 'role.vhost.syntax'],
            ...($this->operclass->isSupported() ? [['name' => 'OPERCLASS', 'desc_key' => 'role.operclass.short', 'help_key' => 'role.operclass.help', 'syntax_key' => 'role.operclass.syntax']] : []),
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
                $this->permissions->handle($context);
                break;
            case 'MODES':
                $this->modes->handle($context);
                break;
            case 'VHOST':
                $this->vhost->handle($context);
                break;
            case 'OPERCLASS':
                if (!$this->operclass->isSupported()) {
                    $context->reply('role.unknown_sub', ['%sub%' => $sub]);

                    break;
                }
                $this->operclass->handle($context);
                break;
            default:
                $context->reply($this->operclass->isSupported() ? 'role.unknown_sub_operclass' : 'role.unknown_sub', ['%sub%' => $sub]);
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
            $context->replyRaw(sprintf('  %-12s%-40s%s', $role->getName(), $role->getDescription(), $protected));
        }
    }
}
