<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
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
    ) {
    }

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

        $firstArg = strtoupper($context->args[0]);

        if ('LIST' === $firstArg) {
            $this->doList($context);

            return;
        }

        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.syntax')]);

            return;
        }

        $nickname = $context->args[0];
        $sub = strtoupper($context->args[1]);

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
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.add.syntax')]);

            return;
        }

        $roleName = strtoupper($context->args[2]);

        $targetAccount = $this->nickRepository->findByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $nickname]);

            return;
        }

        if (!$targetAccount->isRegistered()) {
            $context->reply('ircop.nick_not_active', ['%nick%' => $nickname]);

            return;
        }

        $role = $this->roleRepository->findByName($roleName);
        if (null === $role) {
            $context->reply('ircop.unknown_role', [
                '%role%' => $roleName,
                '%bot%' => $context->getBotName(),
            ]);

            return;
        }

        $existing = $this->ircopRepository->findByNickId($targetAccount->getId());
        if (null !== $existing) {
            $oldRoleName = $existing->getRole()->getName();
            if ($oldRoleName === $roleName) {
                $context->reply('ircop.already_admin', ['%nick%' => $nickname, '%role%' => $roleName]);

                return;
            }

            $oldRole = $existing->getRole();
            $existing->changeRole($role);
            $this->ircopRepository->save($existing);

            $this->modeApplier->removeModesForNick($nickname, $oldRole);
            $this->modeApplier->applyModesForNick($nickname, $role);

            $context->reply('ircop.role_changed', ['%nick%' => $nickname, '%old%' => $oldRoleName, '%new%' => $roleName]);

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

        $context->reply('ircop.add.done', ['%nick%' => $nickname, '%role%' => $roleName]);
    }

    private function doDel(OperServContext $context, string $nickname): void
    {
        $targetAccount = $this->nickRepository->findByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $nickname]);

            return;
        }

        $ircop = $this->ircopRepository->findByNickId($targetAccount->getId());
        if (null === $ircop) {
            $context->reply('ircop.not_admin', ['%nick%' => $nickname]);

            return;
        }

        $role = $ircop->getRole();
        $this->modeApplier->removeModesForNick($nickname, $role);

        $this->ircopRepository->remove($ircop);
        $context->reply('ircop.del.done', ['%nick%' => $nickname]);
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
