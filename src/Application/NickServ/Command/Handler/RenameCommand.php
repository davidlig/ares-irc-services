<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickForceService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\Port\NetworkUserLookupPort;
use Psr\Log\LoggerInterface;

final class RenameCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly NetworkUserLookupPort $userLookup,
        private readonly NickForceService $forceService,
        private readonly NickTargetValidator $targetValidator,
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

        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return;
        }

        $this->forceService->forceGuestNick($onlineUser->uid, null, 'ircop-rename');

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            targetHost: $onlineUser->ident . '@' . $onlineUser->hostname,
            targetIp: $onlineUser->ipBase64,
        );

        $this->logger->info('User renamed via RENAME command', [
            'operator' => $context->sender->nick,
            'target_nick' => $targetNick,
            'target_uid' => $onlineUser->uid,
        ]);

        $context->reply('rename.success', [
            '%nickname%' => $targetNick,
            '%new_nick%' => $this->guestPrefix . 'XXXXXXX',
        ]);
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('rename.cannot_rename_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('rename.cannot_rename_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('rename.cannot_rename_service', ['%nickname%' => $nickname]),
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
