<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Status\StatusNick;
use App\NickServ\Application\UseCase\Status\StatusNickHandlerInterface;
use App\NickServ\Application\UseCase\Status\StatusNickOutcome;
use App\NickServ\Application\UseCase\Status\StatusNickResult;

final readonly class StatusCommand implements NickServCommandInterface
{
    public function __construct(
        private StatusNickHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
    ) {}

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
        return 6;
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

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];
        $onlineUser = $this->userLookup->findByNick($targetNick);

        $result = $this->handler->handle(new StatusNick(
            nickname: $targetNick,
            isOnline: null !== $onlineUser,
            isIdentified: $onlineUser->isIdentified ?? false,
        ));

        $this->present($context, $result);
    }

    private function present(NickServContext $context, StatusNickResult $result): void
    {
        $context->reply('status.header', ['nickname' => $result->nickname]);

        match ($result->outcome) {
            StatusNickOutcome::UnregisteredOffline => $context->reply('status.not_registered_offline'),
            StatusNickOutcome::UnregisteredOnline => $context->reply('status.unregistered'),
            StatusNickOutcome::Pending => $this->presentPending($context, $result),
            StatusNickOutcome::RegisteredNotConnected => $context->reply('status.not_connected'),
            StatusNickOutcome::RegisteredNotIdentified => $context->reply('status.not_identified'),
            StatusNickOutcome::RegisteredIdentified => $context->reply('status.identified'),
            StatusNickOutcome::Suspended => $this->presentSuspended($context, $result),
            StatusNickOutcome::Forbidden => $this->presentForbidden($context, $result),
            StatusNickOutcome::PendingDeletion => $this->presentPendingDeletion($context, $result),
        };

        $context->reply('status.footer');
    }

    private function presentPending(NickServContext $context, StatusNickResult $result): void
    {
        $context->reply('status.pending');

        if (null !== $result->expiresAt) {
            $context->reply('status.pending_expires', ['minutes' => (string) $result->expiresInMinutes]);
            $context->reply('status.pending_expires_at', ['date' => $context->formatDate($result->expiresAt)]);
        }
    }

    private function presentSuspended(NickServContext $context, StatusNickResult $result): void
    {
        $context->reply('status.suspended');

        if (null !== $result->reason) {
            $context->reply('status.suspended_reason', ['reason' => $result->reason]);
        }

        if (null !== $result->suspendedUntil) {
            $context->reply('status.suspended_until', ['date' => $context->formatDate($result->suspendedUntil)]);
        } else {
            $context->reply('status.suspended_permanent');
        }
    }

    private function presentForbidden(NickServContext $context, StatusNickResult $result): void
    {
        $context->reply('status.forbidden');

        if (null !== $result->reason) {
            $context->reply('status.forbidden_reason', ['reason' => $result->reason]);
        }
    }

    private function presentPendingDeletion(NickServContext $context, StatusNickResult $result): void
    {
        $context->reply('status.pending_deletion');

        if (null !== $result->pendingDeletionAt) {
            $context->reply('status.pending_deletion_at', ['date' => $context->formatDate($result->pendingDeletionAt)]);
        }
    }
}
