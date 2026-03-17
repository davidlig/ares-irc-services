<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\AdminAccessHelper;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperAdmin;
use App\Domain\OperServ\Repository\OperAdminRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;

use function count;
use function sprintf;
use function strtoupper;

final readonly class IrcopCommand implements OperServCommandInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private OperAdminRepositoryInterface $adminRepository,
        private OperRoleRepositoryInterface $roleRepository,
        private AdminAccessHelper $accessHelper,
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
            default:
                $context->reply('ircop.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doAdd(OperServContext $context): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.add.syntax')]);

            return;
        }

        $nickname = $context->args[1];
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

        $existing = $this->adminRepository->findByNickId($targetAccount->getId());
        if (null !== $existing) {
            $oldRoleName = $existing->getRole()->getName();
            if ($oldRoleName === $roleName) {
                $context->reply('ircop.already_admin', ['%nick%' => $nickname, '%role%' => $roleName]);

                return;
            }

            $existing->changeRole($role);
            $this->adminRepository->save($existing);
            $context->reply('ircop.role_changed', ['%nick%' => $nickname, '%old%' => $oldRoleName, '%new%' => $roleName]);

            return;
        }

        $admin = OperAdmin::create(
            $targetAccount->getId(),
            $role,
            $context->senderAccount?->getId(),
            null
        );

        $this->adminRepository->save($admin);
        $context->reply('ircop.add.done', ['%nick%' => $nickname, '%role%' => $roleName]);
    }

    private function doDel(OperServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('ircop.del.syntax')]);

            return;
        }

        $nickname = $context->args[1];

        $targetAccount = $this->nickRepository->findByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $nickname]);

            return;
        }

        $admin = $this->adminRepository->findByNickId($targetAccount->getId());
        if (null === $admin) {
            $context->reply('ircop.not_admin', ['%nick%' => $nickname]);

            return;
        }

        $this->adminRepository->remove($admin);
        $context->reply('ircop.del.done', ['%nick%' => $nickname]);
    }

    private function doList(OperServContext $context): void
    {
        $admins = $this->adminRepository->findAll();

        if ([] === $admins) {
            $context->reply('ircop.list.empty');

            return;
        }

        $context->reply('ircop.list.header');

        foreach ($admins as $admin) {
            $nick = $this->nickRepository->findById($admin->getNickId());
            $nickName = null !== $nick ? $nick->getNickname() : (string) $admin->getNickId();
            $roleName = $admin->getRole()->getName();
            $addedAt = $context->formatDate($admin->getAddedAt());
            $context->replyRaw(sprintf('  %-20s %-10s %s', $nickName, $roleName, $addedAt));
        }
    }
}
