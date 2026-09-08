<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;

use function count;
use function sprintf;
use function strtoupper;

final readonly class RoleCommand implements OperServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private RolePermissionsHandler $permissions,
        private RoleOperclassHandler $operclass,
        private RoleModesHandler $modes,
        private RoleVhostHandler $vhost,
        private IrcopAccessHelper $accessHelper,
    ) {}

    public function getAccessHelper(): IrcopAccessHelper
    {
        return $this->accessHelper;
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

    public function getRequiredPermission(): string
    {
        return OperatorAuthorizationAttribute::ROOT;
    }

    public function execute(OperServContext $context): CommandOutcome
    {
        $sub = strtoupper($context->args[0] ?? '');

        switch ($sub) {
            case 'ADD':
                return $this->doAdd($context);
            case 'DEL':
                return $this->doDel($context);
            case 'LIST':
                $this->doList($context);

                return CommandOutcome::rejected();
            case 'PERMS':
                return $this->permissions->handle($context);
            case 'MODES':
                return $this->modes->handle($context);
            case 'VHOST':
                return $this->vhost->handle($context);
            case 'OPERCLASS':
                if (!$this->operclass->isSupported()) {
                    $context->reply('role.unknown_sub', ['%sub%' => $sub]);

                    return CommandOutcome::rejected();
                }

                return $this->operclass->handle($context);
            default:
                $context->reply($this->operclass->isSupported() ? 'role.unknown_sub_operclass' : 'role.unknown_sub', ['%sub%' => $sub]);

                return CommandOutcome::rejected();
        }
    }

    private function doAdd(OperServContext $context): CommandOutcome
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.add.syntax')]);

            return CommandOutcome::rejected();
        }

        $name = strtoupper($context->args[1]);
        $description = $context->args[2] ?? '';

        if ('' === $description) {
            $description = 'Custom role';
        }

        $existing = $this->roleRepository->findByName($name);
        if (null !== $existing) {
            $context->reply('role.already_exists', ['%role%' => $name]);

            return CommandOutcome::rejected();
        }

        $role = OperRole::create($name, $description, false);
        $this->roleRepository->save($role);

        $context->reply('role.add.done', ['%role%' => $name]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $name,
            extra: ['action' => 'ADD'],
        ));
    }

    private function doDel(OperServContext $context): CommandOutcome
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $name = strtoupper($context->args[1]);

        $role = $this->roleRepository->findByName($name);
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => $name]);

            return CommandOutcome::rejected();
        }

        if ($role->isProtected()) {
            $context->reply('role.protected', ['%role%' => $name]);

            return CommandOutcome::rejected();
        }

        $this->roleRepository->remove($role);
        $context->reply('role.del.done', ['%role%' => $name]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $name,
            extra: ['action' => 'DEL'],
        ));
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
