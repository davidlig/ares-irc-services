<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ShowInfo\ChannelInfoView;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfo;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfoHandlerInterface;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;

use function str_contains;

/**
 * INFO <#channel>.
 *
 * Shows channel info: founder, successor, url, email, description,
 * last used, last topic, TOPICLOCK, MLOCK, SECURE.
 */
final readonly class InfoCommand implements ChanServCommandInterface
{
    public function __construct(private ShowChannelInfoHandlerInterface $handler) {}

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
        return 2;
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

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return true;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $info = $this->handler->handle(new ShowChannelInfo($channelName));
        if (null === $info) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        if ($info->forbidden) {
            $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));
            $context->replyRaw($context->trans('info.forbidden_status'));
            if (null !== $info->forbiddenReason) {
                $context->replyRaw($context->trans('info.forbidden_reason', ['%reason%' => $info->forbiddenReason]));
            }
            $context->replyRaw($context->trans('info.footer'));

            return;
        }

        if ($info->pendingDeletion) {
            $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));
            $context->replyRaw($context->trans('info.pending_deletion_status'));
            $pendingDeletionAt = $info->pendingDeletionAt;
            if (null !== $pendingDeletionAt) {
                $context->replyRaw($context->trans('info.pending_deletion_at', ['%date%' => $context->formatDate($pendingDeletionAt)]));
            }
            $expiresAt = $info->pendingDeletionUntil;
            if (null !== $expiresAt) {
                $context->replyRaw($context->trans('info.pending_deletion_until', ['%date%' => $context->formatDate($expiresAt)]));
            }
            $context->replyRaw($context->trans('info.pending_deletion_notice'));
            $context->replyRaw($context->trans('info.footer'));

            return;
        }

        $canShowTopic = $this->canShowTopic($context, $info);

        $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));

        if ($info->suspended) {
            $context->replyRaw($context->trans('info.suspended_status'));
            if (null !== $info->suspendedReason) {
                $context->replyRaw($context->trans('info.suspended_reason', ['%reason%' => $info->suspendedReason]));
            }
            $suspendedUntil = $info->suspendedUntil;
            if (null !== $suspendedUntil) {
                $context->replyRaw($context->trans('info.suspended_until', ['%date%' => $context->formatDate($suspendedUntil)]));
            } else {
                $context->replyRaw($context->trans('info.suspended_permanent'));
            }
        }

        $context->replyRaw($context->trans('info.founder', ['%nickname%' => $info->founderName]));
        if (null !== $info->successorName) {
            $context->replyRaw($context->trans('info.successor', ['%nickname%' => $info->successorName]));
        }
        if ('' !== $info->description) {
            $context->replyRaw($context->trans('info.description', ['%desc%' => $info->description]));
        }
        $context->replyRaw($context->trans('info.registered', ['%date%' => $context->formatDate($info->createdAt)]));
        $context->replyRaw($context->trans('info.last_used', [
            '%date%' => $context->formatDate($info->lastUsedAt),
        ]));
        if (null !== $info->url) {
            $context->replyRaw($context->trans('info.url', ['%url%' => $info->url]));
        }
        if (null !== $info->email) {
            $context->replyRaw($context->trans('info.email', ['%email%' => $info->email]));
        }
        if ($canShowTopic && null !== $info->topic) {
            $context->replyRaw($context->trans('info.topic', [
                '%topic%' => $info->topic,
            ]));
            if (null !== $info->lastTopicSetByNick) {
                $context->replyRaw($context->trans('info.topic_set_by', ['%nickname%' => $info->lastTopicSetByNick]));
            }
        }
        if ($info->mlockActive) {
            $modesDisplay = $info->mlock;
            if ('' === $modesDisplay) {
                $modesDisplay = $context->trans('set.mlock.no_modes');
            }
            $context->replyRaw($context->trans('info.mlock_modes', ['%modes%' => $modesDisplay]));
        }
        $context->replyRaw($context->trans('info.options', [
            '%topiclock%' => $info->topicLock ? 'ON' : 'OFF',
            '%mlock%' => $info->mlockActive ? 'ON' : 'OFF',
            '%secure%' => $info->secure ? 'ON' : 'OFF',
        ]));
        if ($info->noExpire) {
            $context->replyRaw($context->trans('info.no_expire'));
        }
        $context->replyRaw($context->trans('info.footer'));
    }

    private function canShowTopic(ChanServContext $context, ChannelInfoView $info): bool
    {
        if ($this->isSenderFounderOrOper($context, $info)) {
            return true;
        }

        return !$this->isChannelPrivate($context, $info);
    }

    private function isChannelPrivate(ChanServContext $context, ChannelInfoView $info): bool
    {
        $view = $context->getChannelView($context->getChannelNameArg(0) ?? '');
        if (null !== $view && (str_contains($view->modes, 's') || str_contains($view->modes, 'p'))) {
            return true;
        }

        if ($info->mlockActive && (
            str_contains($info->mlock, 's') || str_contains($info->mlock, 'p')
        )) {
            return true;
        }

        return false;
    }

    private function isSenderFounderOrOper(ChanServContext $context, ChannelInfoView $info): bool
    {
        $sender = $context->sender;
        if (null === $sender) {
            return false;
        }

        return $sender->isOper || $this->isSenderChannelFounder($context, $info);
    }

    private function isSenderChannelFounder(ChanServContext $context, ChannelInfoView $info): bool
    {
        if (null === $context->sender || !$context->sender->isIdentified) {
            return false;
        }

        $senderAccount = $context->getSenderAccount();
        if (null !== $senderAccount && $senderAccount->id === $info->founderAccountId) {
            return true;
        }

        return false;
    }
}
