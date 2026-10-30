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
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;

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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('status.syntax', [new Argument('nickname')]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'status.help';
    }

    public function getExampleKey(): string
    {
        return 'status.example';
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

    public function execute(NickServContext $context): null
    {
        $targetNick = $context->args[0];
        $onlineUser = $this->userLookup->findByNick($targetNick);

        $result = $this->handler->handle(new StatusNick(
            nickname: $targetNick,
            isOnline: null !== $onlineUser,
            isIdentified: $onlineUser->isIdentified ?? false,
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, StatusNickResult $result): void
    {
        $context->replyComponent(Component::TableHeader, 'status.header', ['nickname' => new Fragment($result->nickname, Component::Status)]);

        match ($result->outcome) {
            StatusNickOutcome::UnregisteredOffline => $context->replyComponent(Component::Row, 'status.not_registered_offline', [], ['state' => ['status.value_not_registered_offline', Component::Muted]]),
            StatusNickOutcome::UnregisteredOnline => $context->replyComponent(Component::Row, 'status.unregistered'),
            StatusNickOutcome::Pending => $this->presentPending($context, $result),
            StatusNickOutcome::RegisteredNotConnected => $context->replyComponent(Component::Row, 'status.not_connected', [], ['state' => ['status.value_not_connected', Component::Muted]]),
            StatusNickOutcome::RegisteredNotIdentified => $context->replyComponent(Component::Row, 'status.not_identified', [], ['state' => ['status.value_not_identified', Component::Status]]),
            StatusNickOutcome::RegisteredIdentified => $context->replyComponent(Component::Row, 'status.identified', [], ['state' => ['status.value_identified', Component::Positive]]),
            StatusNickOutcome::Suspended => $this->presentSuspended($context, $result),
            StatusNickOutcome::Forbidden => $this->presentForbidden($context, $result),
            StatusNickOutcome::PendingDeletion => $this->presentPendingDeletion($context, $result),
        };

        $context->replySeparator();
    }

    private function presentPending(NickServContext $context, StatusNickResult $result): void
    {
        $context->replyComponent(Component::Row, 'status.pending', [], ['state' => ['status.value_pending', Component::Status]]);

        if (null !== $result->expiresAt) {
            $context->replyComponent(Component::Row, 'status.pending_expires', ['minutes' => (string) $result->expiresInMinutes]);
            $context->replyComponent(Component::Row, 'status.pending_expires_at', ['date' => $context->formatDate($result->expiresAt)]);
        }
    }

    private function presentSuspended(NickServContext $context, StatusNickResult $result): void
    {
        $context->replyComponent(Component::Row, 'status.suspended', [], ['state' => ['status.value_suspended', Component::Danger]]);

        if (null !== $result->reason) {
            $context->replyComponent(Component::Row, 'status.suspended_reason', ['reason' => $result->reason]);
        }

        if (null !== $result->suspendedUntil) {
            $context->replyComponent(Component::Row, 'status.suspended_until', ['date' => $context->formatDate($result->suspendedUntil)]);
        } else {
            $context->replyComponent(Component::Row, 'status.suspended_permanent');
        }
    }

    private function presentForbidden(NickServContext $context, StatusNickResult $result): void
    {
        $context->replyComponent(Component::Row, 'status.forbidden', [], ['state' => ['status.value_forbidden', Component::Danger]]);

        if (null !== $result->reason) {
            $context->replyComponent(Component::Row, 'status.forbidden_reason', ['reason' => $result->reason]);
        }
    }

    private function presentPendingDeletion(NickServContext $context, StatusNickResult $result): void
    {
        $context->replyComponent(Component::Row, 'status.pending_deletion', [], [
            'label' => ['status.label_pending_deletion', Component::Label],
            'state' => ['status.value_pending_deletion', Component::Status],
        ]);

        if (null !== $result->pendingDeletionAt) {
            $context->replyComponent(Component::Row, 'status.pending_deletion_at', ['date' => $context->formatDate($result->pendingDeletionAt)], ['label' => ['status.label_pending_deletion_at', Component::Label]]);
        }
    }
}
