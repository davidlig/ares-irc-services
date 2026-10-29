<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Info\InfoNick;
use App\NickServ\Application\UseCase\Info\InfoNickHandlerInterface;
use App\NickServ\Application\UseCase\Info\InfoNickOutcome;
use App\NickServ\Application\UseCase\Info\InfoNickResult;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function substr;

final readonly class InfoCommand implements NickServCommandInterface
{
    public function __construct(
        private InfoNickHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
    ) {}

    public function getName(): string
    {
        return 'INFO';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('info.syntax', [new Argument('nickname')]);
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
        return 'info.help';
    }

    public function getExampleKey(): string
    {
        return 'info.example';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'info.short';
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
        $sender = $context->sender;
        $onlineUser = $this->userLookup->findByNick($targetNick);

        $result = $this->handler->handle(new InfoNick(
            targetNick: $targetNick,
            senderNick: $sender?->nick,
            senderIsIdentified: $sender->isIdentified ?? false,
            senderIsOper: $sender->isOper ?? false,
            targetIsOnlineAndIdentified: $onlineUser->isIdentified ?? false,
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, InfoNickResult $result): void
    {
        match ($result->outcome) {
            InfoNickOutcome::NotRegistered => $context->replyMissingNick('error.nick_not_registered', $result->nickname),
            InfoNickOutcome::Forbidden => $this->presentForbidden($context, $result),
            InfoNickOutcome::PendingDeletion => $this->presentPendingDeletion($context, $result),
            InfoNickOutcome::Private => $context->reply('info.private', ['nickname' => new Fragment($result->nickname, Component::Emphasis)], Role::Error),
            InfoNickOutcome::Visible => $this->presentVisible($context, $result),
        };
    }

    private function presentForbidden(NickServContext $context, InfoNickResult $result): void
    {
        $context->replyComponent(Component::TableHeader, 'info.header', ['nickname' => new Fragment($result->nickname, Component::Status)]);
        $context->replyComponent(Component::IndentedRow, 'info.status', ['status' => new Fragment($context->trans('info.status_forbidden'), Component::Danger)], ['label' => ['info.label_status', Component::Label]]);
        if (null !== $result->reason) {
            $context->replyComponent(Component::IndentedRow, 'info.reason', ['reason' => $result->reason], ['label' => ['info.label_reason', Component::Label]]);
        }
        $context->replySeparator();
    }

    private function presentPendingDeletion(NickServContext $context, InfoNickResult $result): void
    {
        $context->replyComponent(Component::TableHeader, 'info.header', ['nickname' => new Fragment($result->nickname, Component::Status)]);
        $context->replyComponent(Component::IndentedRow, 'info.status', ['status' => new Fragment($context->trans('info.status_pending_deletion'), Component::Status)], ['label' => ['info.label_status', Component::Label]]);
        if (null !== $result->pendingDeletionAt) {
            $this->replyInfoRow($context, 'info.pending_deletion_at', ['date' => $context->formatDate($result->pendingDeletionAt)]);
        }
        if (null !== $result->deletionExpiresAt) {
            $this->replyInfoRow($context, 'info.pending_deletion_until', ['date' => $context->formatDate($result->deletionExpiresAt)]);
        }
        $context->replyComponent(Component::IndentedRow, 'info.pending_deletion_notice', [], ['notice' => ['info.value_pending_deletion_notice', Component::Status]]);
        $context->replySeparator();
    }

    private function presentVisible(NickServContext $context, InfoNickResult $result): void
    {
        $context->replyComponent(Component::TableHeader, 'info.header', ['nickname' => new Fragment($result->nickname, Component::Status)]);

        $statusKey = NickStatus::Suspended === $result->status ? 'info.status_suspended' : 'info.status_registered';
        $context->replyComponent(Component::IndentedRow, 'info.status', ['status' => new Fragment($context->trans($statusKey), NickStatus::Suspended === $result->status ? Component::Danger : Component::Positive)], ['label' => ['info.label_status', Component::Label]]);

        if (NickStatus::Suspended === $result->status && null !== $result->suspendedReason) {
            $context->replyComponent(Component::IndentedRow, 'info.reason', ['reason' => $result->suspendedReason], ['label' => ['info.label_reason', Component::Label]]);
        }

        if (NickStatus::Suspended === $result->status) {
            if (null !== $result->suspendedUntil) {
                $this->replyInfoRow($context, 'info.suspended_until', ['date' => $context->formatDate($result->suspendedUntil)]);
            } else {
                $this->replyInfoRow($context, 'info.suspended_permanent');
            }
        }

        if (null !== $result->registeredAt) {
            $this->replyInfoRow($context, 'info.registered_at', [
                'date' => $context->formatDate($result->registeredAt),
            ]);
        }

        if ($result->lastSeenOnline) {
            $context->replyComponent(Component::IndentedRow, 'info.last_seen_online', [], [
                'label' => ['info.label_last_seen_online', Component::Label],
                'online' => ['info.value_last_seen_online', Component::Online],
            ]);
        } elseif (null !== $result->lastSeenAt) {
            $this->replyInfoRow($context, 'info.last_seen_at', [
                'date' => $context->formatDate($result->lastSeenAt),
            ]);
        } else {
            $this->replyInfoRow($context, 'info.last_seen_never');
        }

        if (null !== $result->lastQuitMessage) {
            $this->replyInfoRow($context, 'info.last_quit', ['message' => $result->lastQuitMessage]);
        }

        if (null !== $result->lastConnectIp || null !== $result->lastConnectHost) {
            $this->replyInfoRow($context, 'info.last_connect', [
                'ip' => $result->lastConnectIp ?? '*',
                'host' => $result->lastConnectHost ?? '*',
            ]);
        }

        if (null !== $result->language) {
            $this->replyInfoRow($context, 'info.language', ['language' => $result->language]);
        }

        if ($result->isOwnerIdentified && null !== $result->email) {
            $this->replyInfoRow($context, 'info.email', ['email' => $result->email]);
        }

        if ('' !== $result->displayVhost) {
            $this->replyInfoRow($context, 'info.vhost', ['vhost' => $result->displayVhost]);
        }

        if ($result->isNoExpire) {
            $this->replyInfoRow($context, 'info.no_expire');
        }

        if ($result->isOwnerIdentified && [] !== $result->channels) {
            $context->replyComponent(Component::Muted, 'info.channels_header');
            foreach ($result->channels as $channel) {
                if ('access' === $channel->type) {
                    $context->replyComponent(Component::IndentedRow, 'info.channels_entry_access', [
                        'channel' => $channel->name,
                        'level' => $channel->level,
                    ]);
                } elseif ('founder' === $channel->type) {
                    $context->replyComponent(Component::IndentedRow, 'info.channels_entry_founder', [
                        'channel' => $channel->name,
                    ], ['role' => ['info.channels_role_founder', Component::Positive]]);
                } elseif ('successor' === $channel->type) {
                    $context->replyComponent(Component::IndentedRow, 'info.channels_entry_successor', [
                        'channel' => $channel->name,
                    ], ['role' => ['info.channels_role_successor', Component::Positive]]);
                }
            }
        }

        $context->replySeparator();
    }

    /** @param array<string, mixed> $params */
    private function replyInfoRow(NickServContext $context, string $key, array $params = []): void
    {
        $labelKey = 'info.label_' . substr($key, 5);
        $context->replyComponent(Component::IndentedRow, $key, $params, ['label' => [$labelKey, Component::Label]]);
    }
}
