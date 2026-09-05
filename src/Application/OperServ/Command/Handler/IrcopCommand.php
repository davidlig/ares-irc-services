<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\EventBusInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
use function in_array;
use function sprintf;
use function strtoupper;

final readonly class IrcopCommand implements OperServCommandInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private OperIrcopRepositoryInterface $ircopRepository,
        private OperRoleRepositoryInterface $roleRepository,
        private IrcopAccessHelper $accessHelper,
        private IrcopModeApplier $modeApplier,
        private IrcopOperclassApplier $operclassApplier,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function getName(): string
    {
        return 'IRCOP';
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
        return 'ircop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'ircop.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'ircop.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'ircop.add.short', 'help_key' => 'ircop.add.help', 'syntax_key' => 'ircop.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'ircop.del.short', 'help_key' => 'ircop.del.help', 'syntax_key' => 'ircop.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'ircop.list.short', 'help_key' => 'ircop.list.help', 'syntax_key' => 'ircop.list.syntax'],
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

        $firstArg = strtoupper($context->args[0] ?? '');

        if ('LIST' === $firstArg) {
            $this->doList($context);

            return;
        }

        $sub = $firstArg;
        $nickname = $context->args[1] ?? '';

        if (!in_array($firstArg, ['ADD', 'DEL'], true)) {
            $legacySub = strtoupper($context->args[1] ?? '');
            if (in_array($legacySub, ['ADD', 'DEL'], true)) {
                $nickname = $context->args[0];
                $sub = $legacySub;
            } elseif (count($context->args) < 2) {
                $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.syntax')]);

                return;
            } else {
                $sub = $legacySub;
            }
        }

        if ('' === $nickname) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.syntax')]);

            return;
        }

        switch ($sub) {
            case 'ADD':
                $this->doAdd($context, $nickname);
                break;
            case 'DEL':
                $this->doDel($context, $nickname);
                break;
            default:
                $context->reply('ircop.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doAdd(OperServContext $context, string $nickname): void
    {
        $roleName = strtoupper($context->args[2] ?? '');

        $validation = $this->validateIrcopAdd($context, $nickname, $roleName);
        if (null === $validation) {
            return;
        }

        [$targetAccount, $role] = $validation;

        $existing = $this->ircopRepository->findByNickId($targetAccount->getId());

        if (null !== $existing) {
            $oldRole = $existing->getRole();
            if ($oldRole->getName() === $roleName) {
                $context->reply('ircop.already_admin', ['%nickname%' => $nickname, '%role%' => $roleName]);

                return;
            }

            $oldRoleName = $oldRole->getName();
            $existing->changeRole($role);
            $this->ircopRepository->save($existing);

            $this->modeApplier->removeModesForNick($nickname, $oldRole);
            $this->operclassApplier->removeForNick($nickname);
            $this->modeApplier->applyModesForNick($nickname, $role);
            $this->operclassApplier->applyForNick($nickname, $role);
            $this->eventDispatcher->dispatch(new OperIrcopChangedEvent($targetAccount->getId(), $nickname));

            $context->reply('ircop.role_changed', ['%nickname%' => $nickname, '%old%' => $oldRoleName, '%new%' => $roleName]);

            return;
        }

        $ircop = OperIrcop::create(
            $targetAccount->getId(),
            $role,
            $context->senderAccount?->getId(),
            null
        );

        $this->ircopRepository->save($ircop);

        $this->modeApplier->applyModesForNick($nickname, $role);
        $this->operclassApplier->applyForNick($nickname, $role);
        $this->eventDispatcher->dispatch(new OperIrcopChangedEvent($targetAccount->getId(), $nickname));

        $context->reply('ircop.add.done', ['%nickname%' => $nickname, '%role%' => $roleName]);
    }

    /** @return array{0: RegisteredNick, 1: OperRole}|null */
    private function validateIrcopAdd(OperServContext $context, string $nickname, string $roleName): ?array
    {
        return (function () use ($context, $nickname, $roleName): ?array {
            if (count($context->args) < 3) {
                $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.add.syntax')]);

                return null;
            }

            $targetAccount = $this->nickRepository->findByNick($nickname);
            if (null === $targetAccount) {
                $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]);

                return null;
            }

            if (!$targetAccount->isRegistered()) {
                $context->reply('ircop.nick_not_active', ['%nickname%' => $nickname]);

                return null;
            }

            $role = $this->roleRepository->findByName($roleName);
            if (null === $role) {
                $context->reply('ircop.unknown_role', [
                    '%role%' => $roleName,
                    '%bot%' => $context->getBotName(),
                ]);

                return null;
            }

            return [$targetAccount, $role];
        })();
    }

    private function doDel(OperServContext $context, string $nickname): void
    {
        $targetAccount = $this->nickRepository->findByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]);

            return;
        }

        $ircop = $this->ircopRepository->findByNickId($targetAccount->getId());
        if (null === $ircop) {
            $context->reply('ircop.not_admin', ['%nickname%' => $nickname]);

            return;
        }

        $role = $ircop->getRole();
        $this->modeApplier->removeModesForNick($nickname, $role);
        $this->operclassApplier->removeForNick($nickname);

        $this->ircopRepository->remove($ircop);
        $this->eventDispatcher->dispatch(new OperIrcopChangedEvent($targetAccount->getId(), $nickname));
        $context->reply('ircop.del.done', ['%nickname%' => $nickname]);
    }

    private function doList(OperServContext $context): void
    {
        $ircops = $this->ircopRepository->findAll();

        if ([] === $ircops) {
            $context->reply('ircop.list.empty');

            return;
        }

        $context->reply('ircop.list.header');

        foreach ($ircops as $ircop) {
            $nick = $this->nickRepository->findById($ircop->getNickId());
            $nickName = null !== $nick ? $nick->getNickname() : (string) $ircop->getNickId();
            $roleName = $ircop->getRole()->getName();
            $addedAt = $context->formatDate($ircop->getAddedAt());
            $context->replyRaw(sprintf('  %-20s %-10s %s', $nickName, $roleName, $addedAt));
        }
    }
}
