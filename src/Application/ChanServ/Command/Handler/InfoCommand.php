<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\ChanServ\ValueObject\ChannelStatus;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * INFO <#channel>.
 *
 * Shows channel info: founder, successor, url, email, description,
 * last used, last topic, TOPICLOCK, MLOCK, SECURE.
 */
final readonly class InfoCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

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

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        if ($channel->isForbidden()) {
            $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));
            $context->replyRaw($context->trans('info.forbidden_status'));
            if (null !== $channel->getForbiddenReason()) {
                $context->replyRaw($context->trans('info.forbidden_reason', ['%reason%' => $channel->getForbiddenReason()]));
            }
            $context->replyRaw($context->trans('info.footer'));

            return;
        }

        $founderNick = $this->nickRepository->findById($channel->getFounderNickId());
        $founderName = null !== $founderNick ? $founderNick->getNickname() : (string) $channel->getFounderNickId();
        $successorName = null;
        if (null !== $channel->getSuccessorNickId()) {
            $successorNick = $this->nickRepository->findById($channel->getSuccessorNickId());
            $successorName = null !== $successorNick ? $successorNick->getNickname() : (string) $channel->getSuccessorNickId();
        }

        $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));

        if (ChannelStatus::Suspended === $channel->getStatus()) {
            $context->replyRaw($context->trans('info.suspended_status'));
            if (null !== $channel->getSuspendedReason()) {
                $context->replyRaw($context->trans('info.suspended_reason', ['%reason%' => $channel->getSuspendedReason()]));
            }
            $suspendedUntil = $channel->getSuspendedUntil();
            if (null !== $suspendedUntil) {
                $context->replyRaw($context->trans('info.suspended_until', ['%date%' => $context->formatDate($suspendedUntil)]));
            } else {
                $context->replyRaw($context->trans('info.suspended_permanent'));
            }
        }

        $context->replyRaw($context->trans('info.founder', ['%nickname%' => $founderName]));
        if (null !== $successorName) {
            $context->replyRaw($context->trans('info.successor', ['%nickname%' => $successorName]));
        }
        if ('' !== $channel->getDescription()) {
            $context->replyRaw($context->trans('info.description', ['%desc%' => $channel->getDescription()]));
        }
        $context->replyRaw($context->trans('info.registered', ['%date%' => $context->formatDate($channel->getCreatedAt())]));
        $context->replyRaw($context->trans('info.last_used', [
            '%date%' => $context->formatDate($channel->getLastUsedAt()),
        ]));
        if (null !== $channel->getUrl()) {
            $context->replyRaw($context->trans('info.url', ['%url%' => $channel->getUrl()]));
        }
        if (null !== $channel->getEmail()) {
            $context->replyRaw($context->trans('info.email', ['%email%' => $channel->getEmail()]));
        }
        if (null !== $channel->getTopic()) {
            $context->replyRaw($context->trans('info.topic', [
                '%topic%' => $channel->getTopic(),
            ]));
            if (null !== $channel->getLastTopicSetByNick()) {
                $context->replyRaw($context->trans('info.topic_set_by', ['%nickname%' => $channel->getLastTopicSetByNick()]));
            }
        }
        if ($channel->isMlockActive()) {
            $modesDisplay = $channel->getMlock();
            if ('' === $modesDisplay) {
                $modesDisplay = $context->trans('set.mlock.no_modes');
            }
            $context->replyRaw($context->trans('info.mlock_modes', ['%modes%' => $modesDisplay]));
        }
        $context->replyRaw($context->trans('info.options', [
            '%topiclock%' => $channel->isTopicLock() ? 'ON' : 'OFF',
            '%mlock%' => $channel->isMlockActive() ? 'ON' : 'OFF',
            '%secure%' => $channel->isSecure() ? 'ON' : 'OFF',
        ]));
        $context->replyRaw($context->trans('info.footer'));
    }
}
