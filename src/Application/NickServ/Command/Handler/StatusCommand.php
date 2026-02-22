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
 *   - Not registered
 *   - Pending verification (with expiry countdown)
 *   - Registered (with online indicator and registration date)
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

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        $context->replyRaw($context->trans('status.header', ['nickname' => $targetNick]));

        if (null === $account) {
            $context->replyRaw($context->trans('status.unregistered'));
            $context->replyRaw($context->trans('status.footer'));

            return;
        }

        match ($account->getStatus()) {
            NickStatus::Pending => $this->replyPending($context, $account->getExpiresAt()),
            NickStatus::Registered => $this->replyRegistered($context, $targetNick, $account->getRegisteredAt()),
            NickStatus::Suspended => $this->replySuspended($context, $account->getReason()),
            NickStatus::Forbidden => $this->replyForbidden($context, $account->getReason()),
        };

        $context->replyRaw($context->trans('status.footer'));
    }

    private function replyPending(NickServContext $context, ?DateTimeImmutable $expiresAt): void
    {
        $context->replyRaw($context->trans('status.pending'));

        if (null !== $expiresAt) {
            $minutes = max(0, (int) ceil(($expiresAt->getTimestamp() - time()) / 60));
            $context->replyRaw($context->trans('status.pending_expires', ['minutes' => $minutes]));
        }
    }

    private function replyRegistered(
        NickServContext $context,
        string $targetNick,
        ?DateTimeImmutable $registeredAt,
    ): void {
        $context->replyRaw($context->trans('status.registered'));

        try {
            $onlineUser = $this->userRepository->findByNick(new Nick($targetNick));
            if (null !== $onlineUser) {
                $context->replyRaw($context->trans('status.online_now'));
            }
        } catch (InvalidArgumentException) {
        }

        if (null !== $registeredAt) {
            $context->replyRaw($context->trans('status.registered_at', [
                'date' => $registeredAt->format('d/m/Y H:i'),
            ]));
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
