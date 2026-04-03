<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;

use function strtolower;
use function substr;
use function uniqid;

final class RenameCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly NetworkUserLookupPort $userLookup,
        private readonly NickServNotifierInterface $notifier,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
        private readonly RootUserRegistry $rootRegistry,
        private readonly LoggerInterface $logger,
        private readonly string $guestPrefix = 'Guest-',
    ) {
    }

    public function getName(): string
    {
        return 'RENAME';
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
        return 'rename.syntax';
    }

    public function getHelpKey(): string
    {
        return 'rename.help';
    }

    public function getOrder(): int
    {
        return 65;
    }

    public function getShortDescKey(): string
    {
        return 'rename.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::RENAME;
    }

    public function getHelpParams(): array
    {
        return ['%prefix%' => $this->guestPrefix];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];

        $onlineUser = $this->userLookup->findByNick($targetNick);

        if (null === $onlineUser) {
            $context->reply('rename.not_online', ['%nickname%' => $targetNick]);

            return;
        }

        $targetNickLower = strtolower($targetNick);

        if ($this->rootRegistry->isRoot($targetNickLower)) {
            $context->reply('rename.cannot_rename_root', ['%nickname%' => $targetNick]);

            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null !== $account) {
            $ircop = $this->ircopRepository->findByNickId($account->getId());

            if (null !== $ircop) {
                $context->reply('rename.cannot_rename_oper', ['%nickname%' => $targetNick]);

                return;
            }
        }

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $this->notifier->forceNick($onlineUser->uid, $guestNick);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            targetHost: $onlineUser->ident . '@' . $onlineUser->hostname,
            targetIp: $onlineUser->ipBase64,
        );

        $this->logger->info('User renamed via RENAME command', [
            'operator' => $context->sender->nick,
            'target_nick' => $targetNick,
            'target_uid' => $onlineUser->uid,
            'new_nick' => $guestNick,
        ]);

        $context->reply('rename.success', [
            '%nickname%' => $targetNick,
            '%new_nick%' => $guestNick,
        ]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
