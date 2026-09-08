<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\OperclassServiceActionsInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
use function implode;
use function sprintf;
use function strcasecmp;
use function strtoupper;
use function trim;

final readonly class RoleOperclassHandler
{
    public function __construct(
        private OperRoleRepositoryInterface $roleRepository,
        private ActiveConnectionHolderInterface $connectionHolder,
        private IrcopOperclassApplier $operclassApplier,
    ) {}

    public function isSupported(): bool
    {
        return $this->getActions() instanceof OperclassServiceActionsInterface;
    }

    public function handle(OperServContext $context): CommandOutcome
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.operclass.syntax')]);

            return CommandOutcome::rejected();
        }

        if ('LIST' === strtoupper($context->args[1])) {
            $this->listOperclasses($context);

            return CommandOutcome::rejected();
        }

        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('role.operclass.syntax')]);

            return CommandOutcome::rejected();
        }

        $role = $this->roleRepository->findByName(strtoupper($context->args[1]));
        if (null === $role) {
            $context->reply('role.not_found', ['%role%' => strtoupper($context->args[1])]);

            return CommandOutcome::rejected();
        }

        $action = strtoupper($context->args[2]);
        if ('VIEW' === $action || 'LIST' === $action) {
            $this->viewOperclass($context, $role);

            return CommandOutcome::rejected();
        }

        if ('SET' === $action) {
            return $this->setOperclass($context, $role);
        }

        $context->reply('role.operclass.unknown_action', ['%action%' => $action]);

        return CommandOutcome::rejected();
    }

    private function listOperclasses(OperServContext $context): void
    {
        $available = $this->getActions()?->getAvailableOperclasses();
        if (null === $available) {
            $context->reply('role.operclass.list.not_supported');

            return;
        }

        if ([] === $available) {
            $context->reply('role.operclass.list.empty');

            return;
        }

        $context->reply('role.operclass.list.header');
        foreach ($available as $operclass) {
            $context->replyRaw(sprintf('  %s', $operclass));
        }
    }

    private function viewOperclass(OperServContext $context, OperRole $role): void
    {
        $operclass = $role->getOperclass();
        if (null === $operclass || '' === $operclass) {
            $context->reply('role.operclass.view.empty', ['%role%' => $role->getName()]);
        } else {
            $context->reply('role.operclass.view.line', ['%operclass%' => $operclass]);
        }

        $available = $this->getActions()?->getAvailableOperclasses();
        if (null !== $available && [] !== $available) {
            $context->reply('role.operclass.view.available', ['%available%' => implode(', ', $available)]);
        }
    }

    private function setOperclass(OperServContext $context, OperRole $role): CommandOutcome
    {
        $operclassArg = trim($context->args[3] ?? '');
        $operclass = '' === $operclassArg || 'OFF' === strtoupper($operclassArg) ? null : $operclassArg;

        if (null !== $operclass) {
            $available = $this->getActions()?->getAvailableOperclasses();
            if (null !== $available && [] !== $available) {
                $matched = null;
                foreach ($available as $candidate) {
                    if (0 === strcasecmp($candidate, $operclass)) {
                        $matched = $candidate;
                        break;
                    }
                }

                if (null === $matched) {
                    $context->reply('role.operclass.set.not_available', [
                        '%operclass%' => $operclass,
                        '%available%' => implode(', ', $available),
                    ]);

                    return CommandOutcome::rejected();
                }

                $operclass = $matched;
            }
        }

        $role->changeOperclass($operclass);
        $this->roleRepository->save($role);
        $this->operclassApplier->updateForRole($role->getId(), $operclass);

        $context->reply(null === $operclass ? 'role.operclass.set.cleared' : 'role.operclass.set.done', ['%role%' => $role->getName()]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $role->getName(),
            extra: ['action' => null === $operclass ? 'OPERCLASS_CLEAR' : 'OPERCLASS_SET'],
        ));
    }

    private function getActions(): ?OperclassServiceActionsInterface
    {
        $actions = $this->connectionHolder->getProtocolModule()?->getServiceActions();

        return $actions instanceof OperclassServiceActionsInterface ? $actions : null;
    }
}
