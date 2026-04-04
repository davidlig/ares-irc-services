<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

use function array_slice;
use function implode;
use function strtolower;
use function trim;

final class ForbidCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
        private readonly ForbiddenNickService $forbiddenService,
        private readonly NickDropService $dropService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'FORBID';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'forbid.syntax';
    }

    public function getHelpKey(): string
    {
        return 'forbid.help';
    }

    public function getOrder(): int
    {
        return 72;
    }

    public function getShortDescKey(): string
    {
        return 'forbid.short';
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
        return NickServPermission::FORBID;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];
        $reasonParts = array_slice($context->args, 1);
        $reason = trim(implode(' ', $reasonParts));

        if ('' === $reason) {
            $context->reply('forbid.reason_required');

            return;
        }

        $targetNickLower = strtolower($targetNick);

        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null !== $account && $account->isForbidden()) {
            $this->forbiddenService->updateReason($account, $reason);
            $this->logger->info('Nickname forbidden reason updated', [
                'operator' => $context->sender->nick,
                'nickname' => $targetNick,
                'reason' => $reason,
            ]);
            $context->reply('forbid.updated', ['%nickname%' => $targetNick]);

            return;
        }

        if (null !== $account && !$account->isForbidden()) {
            $this->dropService->dropNick($account, 'forbid', $context->sender->nick);
        }

        $this->forbiddenService->forbid($targetNick, $reason, $context->sender->nick);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['reason' => $reason],
        );

        $this->logger->info('Nickname forbidden via FORBID command', [
            'operator' => $context->sender->nick,
            'nickname' => $targetNick,
            'reason' => $reason,
        ]);

        $context->reply('forbid.success', ['%nickname%' => $targetNick]);
    }

    private function replyProtectabilityError(NickServContext $context, \App\Application\NickServ\Service\NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            \App\Application\NickServ\Service\NickProtectabilityStatus::IsRoot => $context->reply('forbid.cannot_forbid_root', ['%nickname%' => $nickname]),
            \App\Application\NickServ\Service\NickProtectabilityStatus::IsIrcop => $context->reply('forbid.cannot_forbid_oper', ['%nickname%' => $nickname]),
            \App\Application\NickServ\Service\NickProtectabilityStatus::IsService => $context->reply('forbid.cannot_forbid_service', ['%nickname%' => $nickname]),
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
