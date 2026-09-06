<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function array_diff;
use function count;
use function implode;
use function strtoupper;

final readonly class RoleModesHandler
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private ActiveConnectionHolderInterface $connectionHolder,
        private IrcopModeApplier $modeApplier,
    ) {}

    public function handle(OperServContext $context): void
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
        $context->reply('role.modes.view.line', ['%modes%' => '+' . implode('', $modes)]);
    }

    private function setModes(OperServContext $context, OperRole $role): void
    {
        $modesArg = $context->args[3] ?? '';

        $protocolModule = $this->connectionHolder->getProtocolModule();
        if (null === $protocolModule) {
            $context->reply('role.modes.set.no_irc_user_modes');

            return;
        }

        $validModes = $protocolModule->getUserModeSupport()->getIrcOpUserModes();
        if (empty($validModes)) {
            $context->reply('role.modes.set.not_supported');

            return;
        }

        $oldModes = $role->getUserModes();

        if ('' === $modesArg) {
            $role->changeUserModes([]);
            $this->roleRepository->save($role);
            $this->modeApplier->updateModesForRole($role->getId(), $oldModes, []);
            $context->reply('role.modes.set.cleared', ['%role%' => $role->getName()]);

            return;
        }

        $modes = array_values(array_unique(str_split(ltrim($modesArg, '+'))));
        $invalidModes = array_diff($modes, $validModes);
        if (!empty($invalidModes)) {
            $context->reply('role.modes.set.invalid_modes', [
                '%invalid%' => '+' . implode('', $invalidModes),
                '%valid%' => '+' . implode('', $validModes),
            ]);

            return;
        }

        $role->changeUserModes($modes);
        $this->roleRepository->save($role);
        $this->modeApplier->updateModesForRole($role->getId(), $oldModes, $modes);
        $context->reply('role.modes.set.done', [
            '%modes%' => '+' . implode('', $modes),
            '%role%' => $role->getName(),
        ]);
    }
}
