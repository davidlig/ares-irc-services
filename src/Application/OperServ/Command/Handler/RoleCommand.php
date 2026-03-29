<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\VhostValidator;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_diff;
use function count;
use function implode;
use function in_array;
use function sprintf;
use function strtoupper;
use function trim;

final readonly class RoleCommand implements OperServCommandInterface
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private OperPermissionRepositoryInterface $permissionRepository,
        private IrcopAccessHelper $accessHelper,
        private PermissionRegistry $permissionRegistry,
        private ActiveConnectionHolderInterface $connectionHolder,
        private IdentifiedSessionRegistry $identifiedRegistry,
        private IrcopModeApplier $modeApplier,
        private ForcedVhostApplier $vhostApplier,
        private VhostValidator $vhostValidator,
        private EventDispatcherInterface $eventDispatcher,
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
            ['name' => 'MODES', 'desc_key' => 'role.modes.short', 'help_key' => 'role.modes.help', 'syntax_key' => 'role.modes.syntax'],
            ['name' => 'VHOST', 'desc_key' => 'role.vhost.short', 'help_key' => 'role.vhost.help', 'syntax_key' => 'role.vhost.syntax'],
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
            case 'MODES':
                $this->doModes($context);
                break;
            case 'VHOST':
                $this->doVhost($context);
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
            $context->replyRaw(sprintf('  %-12s%-40s%s', $role->getName(), $role->getDescription(), $protected));
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
        $assignedPermissions = [];
        foreach ($role->getPermissions() as $permission) {
            $assignedPermissions[] = $permission->getName();
        }

        $allPermissions = $this->permissionRegistry->getAllPermissions();
        $availablePermissions = array_diff($allPermissions, $assignedPermissions);

        if (empty($assignedPermissions) && empty($availablePermissions)) {
            $context->reply('role.perms.list.empty', ['%role%' => $role->getName()]);

            return;
        }

        $context->reply('role.perms.list.header', ['%role%' => $role->getName()]);

        if (!empty($assignedPermissions)) {
            $context->reply('role.perms.list.assigned');
            foreach ($assignedPermissions as $perm) {
                $description = $context->trans('permissions.' . $perm, [], 'operserv');
                if (str_starts_with($description, 'permissions.')) {
                    $context->replyRaw(sprintf('  %s', $perm));
                } else {
                    $context->replyRaw(sprintf('  %s - %s', $perm, $description));
                }
            }
        } else {
            $context->reply('role.perms.list.none_assigned');
        }

        if (!empty($availablePermissions)) {
            $context->reply('role.perms.list.available');
            foreach ($availablePermissions as $perm) {
                $description = $context->trans('permissions.' . $perm, [], 'operserv');
                if (str_starts_with($description, 'permissions.')) {
                    $context->replyRaw(sprintf('  %s', $perm));
                } else {
                    $context->replyRaw(sprintf('  %s - %s', $perm, $description));
                }
            }
        } else {
            $context->reply('role.perms.list.all_assigned');
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

    private function doModes(OperServContext $context): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.modes.syntax')]);

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
            case 'VIEW':
                $this->viewModes($context, $role);
                break;
            case 'SET':
                $this->setModes($context, $role);
                break;
            default:
                $context->reply('role.modes.unknown_action', ['%action%' => $action]);
        }
    }

    private function viewModes(OperServContext $context, OperRole $role): void
    {
        $modes = $role->getUserModes();

        if (empty($modes)) {
            $context->reply('role.modes.view.empty', ['%role%' => $role->getName()]);

            return;
        }

        $context->reply('role.modes.view.header', ['%role%' => $role->getName()]);
        $modesStr = '+' . implode('', $modes);
        $context->reply('role.modes.view.line', ['%modes%' => $modesStr]);
    }

    private function setModes(OperServContext $context, OperRole $role): void
    {
        $modesArg = $context->args[3] ?? '';

        $protocolModule = $this->connectionHolder->getProtocolModule();
        if (null === $protocolModule) {
            $context->reply('role.modes.set.no_irc_user_modes');

            return;
        }

        $userModeSupport = $protocolModule->getUserModeSupport();
        $validModes = $userModeSupport->getIrcOpUserModes();

        $oldModes = $role->getUserModes();

        if ('' === $modesArg) {
            $role->setUserModes([]);
            $this->roleRepository->save($role);

            $this->modeApplier->updateModesForRole($role->getId(), $oldModes, []);

            $context->reply('role.modes.set.cleared', ['%role%' => $role->getName()]);

            return;
        }

        $modesStr = ltrim($modesArg, '+');
        $modes = str_split($modesStr);
        $modes = array_unique($modes);

        $invalidModes = array_diff($modes, $validModes);
        if (!empty($invalidModes)) {
            $context->reply('role.modes.set.invalid_modes', [
                '%invalid%' => '+' . implode('', $invalidModes),
                '%valid%' => '+' . implode('', $validModes),
            ]);

            return;
        }

        $role->setUserModes($modes);
        $this->roleRepository->save($role);

        $this->modeApplier->updateModesForRole($role->getId(), $oldModes, $modes);

        $context->reply('role.modes.set.done', [
            '%modes%' => '+' . implode('', $modes),
            '%role%' => $role->getName(),
        ]);
    }

    private function doVhost(OperServContext $context): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.vhost.syntax')]);

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
            case 'VIEW':
                $this->viewVhost($context, $role);
                break;
            case 'SET':
                $this->setVhost($context, $role);
                break;
            default:
                $context->reply('role.vhost.unknown_action', ['%action%' => $action]);
        }
    }

    private function viewVhost(OperServContext $context, OperRole $role): void
    {
        $pattern = $role->getForcedVhostPattern();

        if (null === $pattern || '' === $pattern) {
            $context->reply('role.vhost.view.empty', ['%role%' => $role->getName()]);

            return;
        }

        $context->reply('role.vhost.view.header', ['%role%' => $role->getName()]);
        $context->reply('role.vhost.view.line', ['%pattern%' => $pattern]);
        $context->reply('role.vhost.view.example', ['%pattern%' => $pattern]);
    }

    private function setVhost(OperServContext $context, OperRole $role): void
    {
        $patternArg = $context->args[3] ?? '';

        $normalized = trim($patternArg);
        $clearKeywords = ['OFF', ''];

        if ('' === $normalized || in_array(strtoupper($normalized), $clearKeywords, true)) {
            $role->setForcedVhostPattern(null);
            $this->roleRepository->save($role);

            $this->vhostApplier->updateVhostForRole($role->getId(), null);

            $context->reply('role.vhost.set.cleared', ['%role%' => $role->getName()]);

            return;
        }

        if (!$this->vhostValidator->isValid($normalized)) {
            $context->reply('role.vhost.set.invalid');

            return;
        }

        if (!ForcedVhost::isValidPattern($normalized)) {
            $context->reply('role.vhost.set.invalid');

            return;
        }

        $role->setForcedVhostPattern($normalized);
        $this->roleRepository->save($role);

        $this->vhostApplier->updateVhostForRole($role->getId(), $normalized);

        $context->reply('role.vhost.set.done', ['%role%' => $role->getName()]);
    }
}
