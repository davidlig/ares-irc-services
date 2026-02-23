<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * STATUS <nickname>.
 *
 * Displays the registration state of a nickname:
 *   - Not registered (offline) / Sin registrar (online)
 *   - Pending verification (with expiry countdown)
 *   - Registered: Not connected / Not identified / Identified
 *   - Suspended (with reason)
 *   - Forbidden (with reason)
 */
final readonly class StatusCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
    ) {
    }

    public function getName(): string
    {
        return 'STATUS';
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
        return 'status.syntax';
    }

    public function getHelpKey(): string
    {
        return 'status.help';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'status.short';
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
        return null;
    }

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        $context->replyRaw($context->trans('status.header', ['nickname' => $targetNick]));

        if (null === $account) {
            $this->replyUnregistered($context, $targetNick);
            $context->replyRaw($context->trans('status.footer'));

            return;
        }

        match ($account->getStatus()) {
            NickStatus::Pending => $this->replyPending($context, $account->getExpiresAt()),
            NickStatus::Registered => $this->replyRegistered($context, $targetNick),
            NickStatus::Suspended => $this->replySuspended($context, $account->getReason()),
            NickStatus::Forbidden => $this->replyForbidden($context, $account->getReason()),
        };

        $context->replyRaw($context->trans('status.footer'));
    }

    private function replyUnregistered(NickServContext $context, string $targetNick): void
    {
        try {
            $onlineUser = $this->userRepository->findByNick(new Nick($targetNick));
        } catch (InvalidArgumentException) {
            $onlineUser = null;
        }

        if (null === $onlineUser) {
            $context->replyRaw($context->trans('status.not_registered_offline'));
        } else {
            $context->replyRaw($context->trans('status.unregistered'));
        }
    }

    private function replyPending(NickServContext $context, ?DateTimeImmutable $expiresAt): void
    {
        $context->replyRaw($context->trans('status.pending'));

        if (null !== $expiresAt) {
            $minutes = max(0, (int) ceil(($expiresAt->getTimestamp() - time()) / 60));
            $context->replyRaw($context->trans('status.pending_expires', ['minutes' => $minutes]));
            $context->replyRaw($context->trans('status.pending_expires_at', ['date' => $context->formatDate($expiresAt)]));
        }
    }

    private function replyRegistered(NickServContext $context, string $targetNick): void
    {
        try {
            $onlineUser = $this->userRepository->findByNick(new Nick($targetNick));
        } catch (InvalidArgumentException) {
            $onlineUser = null;
        }

        if (null === $onlineUser) {
            $context->replyRaw($context->trans('status.not_connected'));
        } elseif (!$onlineUser->isIdentified()) {
            $context->replyRaw($context->trans('status.not_identified'));
        } else {
            $context->replyRaw($context->trans('status.identified'));
        }
    }

    private function replySuspended(NickServContext $context, ?string $reason): void
    {
        $context->replyRaw($context->trans('status.suspended'));

        if (null !== $reason) {
            $context->replyRaw($context->trans('status.suspended_reason', ['reason' => $reason]));
        }
    }

    private function replyForbidden(NickServContext $context, ?string $reason): void
    {
        $context->replyRaw($context->trans('status.forbidden'));

        if (null !== $reason) {
            $context->replyRaw($context->trans('status.forbidden_reason', ['reason' => $reason]));
        }
    }
}
