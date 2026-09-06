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

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'info.syntax';
    }

    public function getHelpKey(): string
    {
        return 'info.help';
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

    public function execute(NickServContext $context): void
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
    }

    private function present(NickServContext $context, InfoNickResult $result): void
    {
        match ($result->outcome) {
            InfoNickOutcome::NotRegistered => $context->reply('info.not_registered', ['nickname' => $result->nickname]),
            InfoNickOutcome::Forbidden => $this->presentForbidden($context, $result),
            InfoNickOutcome::PendingDeletion => $this->presentPendingDeletion($context, $result),
            InfoNickOutcome::Private => $context->reply('info.private', ['nickname' => $result->nickname]),
            InfoNickOutcome::Visible => $this->presentVisible($context, $result),
        };
    }

    private function presentForbidden(NickServContext $context, InfoNickResult $result): void
    {
        $context->reply('info.header', ['nickname' => $result->nickname]);
        $context->reply('info.status', ['status' => $context->trans('info.status_forbidden')]);
        if (null !== $result->reason) {
            $context->reply('info.reason', ['reason' => $result->reason]);
        }
        $context->reply('info.footer');
    }

    private function presentPendingDeletion(NickServContext $context, InfoNickResult $result): void
    {
        $context->reply('info.header', ['nickname' => $result->nickname]);
        $context->reply('info.status', ['status' => $context->trans('info.status_pending_deletion')]);
        if (null !== $result->pendingDeletionAt) {
            $context->reply('info.pending_deletion_at', ['date' => $context->formatDate($result->pendingDeletionAt)]);
        }
        if (null !== $result->deletionExpiresAt) {
            $context->reply('info.pending_deletion_until', ['date' => $context->formatDate($result->deletionExpiresAt)]);
        }
        $context->reply('info.pending_deletion_notice');
        $context->reply('info.footer');
    }

    private function presentVisible(NickServContext $context, InfoNickResult $result): void
    {
        $context->reply('info.header', ['nickname' => $result->nickname]);

        $statusKey = NickStatus::Suspended === $result->status ? 'info.status_suspended' : 'info.status_registered';
        $context->reply('info.status', ['status' => $context->trans($statusKey)]);

        if (NickStatus::Suspended === $result->status && null !== $result->suspendedReason) {
            $context->reply('info.reason', ['reason' => $result->suspendedReason]);
        }

        if (NickStatus::Suspended === $result->status) {
            if (null !== $result->suspendedUntil) {
                $context->reply('info.suspended_until', ['date' => $context->formatDate($result->suspendedUntil)]);
            } else {
                $context->reply('info.suspended_permanent');
            }
        }

        if (null !== $result->registeredAt) {
            $context->reply('info.registered_at', [
                'date' => $context->formatDate($result->registeredAt),
            ]);
        }

        if ($result->lastSeenOnline) {
            $context->reply('info.last_seen_online');
        } elseif (null !== $result->lastSeenAt) {
            $context->reply('info.last_seen_at', [
                'date' => $context->formatDate($result->lastSeenAt),
            ]);
        } else {
            $context->reply('info.last_seen_never');
        }

        if (null !== $result->lastQuitMessage) {
            $context->reply('info.last_quit', ['message' => $result->lastQuitMessage]);
        }

        if (null !== $result->lastConnectIp || null !== $result->lastConnectHost) {
            $context->reply('info.last_connect', [
                'ip' => $result->lastConnectIp ?? '*',
                'host' => $result->lastConnectHost ?? '*',
            ]);
        }

        if (null !== $result->language) {
            $context->reply('info.language', ['language' => $result->language]);
        }

        if ($result->isOwnerIdentified && null !== $result->email) {
            $context->reply('info.email', ['email' => $result->email]);
        }

        if ('' !== $result->displayVhost) {
            $context->reply('info.vhost', ['vhost' => $result->displayVhost]);
        }

        if ($result->isNoExpire) {
            $context->reply('info.no_expire');
        }

        if ($result->isOwnerIdentified && [] !== $result->channels) {
            $context->reply('info.channels_header');
            foreach ($result->channels as $channel) {
                if ('access' === $channel->type) {
                    $context->reply('info.channels_entry_access', [
                        'channel' => $channel->name,
                        'level' => $channel->level,
                    ]);
                } elseif ('founder' === $channel->type) {
                    $context->reply('info.channels_entry_founder', [
                        'channel' => $channel->name,
                    ]);
                } elseif ('successor' === $channel->type) {
                    $context->reply('info.channels_entry_successor', [
                        'channel' => $channel->name,
                    ]);
                }
            }
        }

        $context->reply('info.footer');
    }
}
