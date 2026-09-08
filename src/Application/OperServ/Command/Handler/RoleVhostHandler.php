<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\Port\EventBusInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\NickServ\Application\Service\VhostValidator;

use function count;
use function in_array;
use function strtoupper;
use function trim;

final readonly class RoleVhostHandler
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private ForcedVhostApplier $vhostApplier,
        private VhostValidator $vhostValidator,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(OperServContext $context): CommandOutcome
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.vhost.syntax')]);

            return CommandOutcome::rejected();
        }

        $roleName = strtoupper($context->args[1]);
        $action = strtoupper($context->args[2]);

        $role = $this->roleRepository->findByName($roleName);
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => $roleName]);

            return CommandOutcome::rejected();
        }

        switch ($action) {
            case 'VIEW':
                $this->viewVhost($context, $role);

                return CommandOutcome::rejected();
            case 'SET':
                return $this->setVhost($context, $role);
            default:
                $context->reply('role.vhost.unknown_action', ['%action%' => $action]);

                return CommandOutcome::rejected();
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

    private function setVhost(OperServContext $context, OperRole $role): CommandOutcome
    {
        $normalized = trim($context->args[3] ?? '');
        if ('' === $normalized || in_array(strtoupper($normalized), ['OFF', ''], true)) {
            $this->changeVhost($role, null);
            $context->reply('role.vhost.set.cleared', ['%role%' => $role->getName()]);

            return CommandOutcome::success(new IrcopAuditData(
                target: $role->getName(),
                extra: ['action' => 'VHOST_CLEAR'],
            ));
        }

        if (!$this->vhostValidator->isValid($normalized) || !ForcedVhost::isValidPattern($normalized)) {
            $context->reply('role.vhost.set.invalid');

            return CommandOutcome::rejected();
        }

        $this->changeVhost($role, $normalized);
        $context->reply('role.vhost.set.done', ['%role%' => $role->getName()]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => 'VHOST_SET'],
        ));
    }

    private function changeVhost(OperRole $role, ?string $pattern): void
    {
        $role->changeForcedVhostPattern($pattern);
        $this->roleRepository->save($role);
        $this->vhostApplier->updateVhostForRole($role->getId(), $pattern);
        $this->eventDispatcher->dispatch(new OperRoleForcedVhostChangedEvent($role->getId(), $pattern));
    }
}
