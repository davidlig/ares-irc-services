<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ShowInfo\ChannelInfoView;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfo;
use App\ChanServ\Application\UseCase\ShowInfo\ShowChannelInfoHandlerInterface;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use DateTimeImmutable;

use function implode;
use function str_contains;

/**
 * INFO <#channel>.
 *
 * Shows channel info: founder, successor, url, email, description,
 * last used, last topic, TOPICLOCK, MLOCK, SECURE, IRCOPONLY.
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

        $sender = $context->sender;
        $senderIsIdentified = null !== $sender && $sender->isIdentified;
        $info = $this->handler->handle(new ShowChannelInfo(
            channelName: $channelName,
            requesterAccountId: $senderIsIdentified ? $context->senderAccount?->id : null,
            requesterIsIdentified: $senderIsIdentified,
            requesterIsOper: null !== $sender && $sender->isOper,
        ));
        if (null === $info) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        $this->present($context, $channelName, $info);
    }

    private function present(ChanServContext $context, string $channelName, ChannelInfoView $info): void
    {
        if ($info->privateInfoDenied) {
            $context->reply('info.private', ['%channel%' => $channelName]);

            return;
        }

        if ($info->forbidden) {
            $this->presentForbidden($context, $channelName, $info);

            return;
        }

        if ($info->pendingDeletion) {
            $this->presentPendingDeletion($context, $channelName, $info);

            return;
        }

        $this->presentRegisteredChannel($context, $channelName, $info);
    }

    private function presentForbidden(ChanServContext $context, string $channelName, ChannelInfoView $info): void
    {
        $this->presentHeader($context, $channelName);
        $context->replyRaw($context->trans('info.forbidden_status'));
        $this->presentOptionalLine($context, 'info.forbidden_reason', '%reason%', $info->forbiddenReason);
        $this->presentFooter($context);
    }

    private function presentPendingDeletion(ChanServContext $context, string $channelName, ChannelInfoView $info): void
    {
        $this->presentHeader($context, $channelName);
        $context->replyRaw($context->trans('info.pending_deletion_status'));
        $this->presentOptionalDate($context, 'info.pending_deletion_at', $info->pendingDeletionAt);
        $this->presentOptionalDate($context, 'info.pending_deletion_until', $info->pendingDeletionUntil);
        $context->replyRaw($context->trans('info.pending_deletion_notice'));
        $this->presentFooter($context);
    }

    private function presentRegisteredChannel(ChanServContext $context, string $channelName, ChannelInfoView $info): void
    {
        $canShowTopic = $this->canShowTopic($context, $info);

        $this->presentHeader($context, $channelName);
        $this->presentSuspendedStatus($context, $info);
        $this->presentChannelIdentity($context, $info);
        $context->replyRaw($context->trans('info.last_used', [
            '%date%' => $context->formatDate($info->lastUsedAt),
        ]));
        $this->presentTopic($context, $info, $canShowTopic);
        $this->presentContactDetails($context, $info);
        $this->presentMlockModes($context, $info);
        $this->presentEnabledOptions($context, $info);
        $this->presentNoExpire($context, $info);
        $this->presentFooter($context);
    }

    private function presentHeader(ChanServContext $context, string $channelName): void
    {
        $context->replyRaw($context->trans('info.header', ['%channel%' => $channelName]));
    }

    private function presentFooter(ChanServContext $context): void
    {
        $context->replyRaw($context->trans('info.footer'));
    }

    private function presentSuspendedStatus(ChanServContext $context, ChannelInfoView $info): void
    {
        if (!$info->suspended) {
            return;
        }

        $context->replyRaw($context->trans('info.suspended_status'));
        $this->presentOptionalLine($context, 'info.suspended_reason', '%reason%', $info->suspendedReason);

        if (null === $info->suspendedUntil) {
            $context->replyRaw($context->trans('info.suspended_permanent'));

            return;
        }

        $this->presentOptionalDate($context, 'info.suspended_until', $info->suspendedUntil);
    }

    private function presentChannelIdentity(ChanServContext $context, ChannelInfoView $info): void
    {
        $context->replyRaw($context->trans('info.founder', ['%nickname%' => $info->founderName]));
        $this->presentOptionalLine($context, 'info.successor', '%nickname%', $info->successorName);

        if ('' !== $info->description) {
            $context->replyRaw($context->trans('info.description', ['%desc%' => $info->description]));
        }

        $context->replyRaw($context->trans('info.registered', ['%date%' => $context->formatDate($info->createdAt)]));
    }

    private function presentTopic(ChanServContext $context, ChannelInfoView $info, bool $canShowTopic): void
    {
        if (!$canShowTopic || null === $info->topic) {
            return;
        }

        $context->replyRaw($context->trans('info.topic', ['%topic%' => $info->topic]));
        $this->presentOptionalLine($context, 'info.topic_set_by', '%nickname%', $info->lastTopicSetByNick);
    }

    private function presentContactDetails(ChanServContext $context, ChannelInfoView $info): void
    {
        $this->presentOptionalLine($context, 'info.url', '%url%', $info->url);
        $this->presentOptionalLine($context, 'info.email', '%email%', $info->email);
    }

    private function presentMlockModes(ChanServContext $context, ChannelInfoView $info): void
    {
        if (!$info->mlockActive) {
            return;
        }

        $modesDisplay = '' !== $info->mlock ? $info->mlock : $context->trans('set.mlock.no_modes');
        $context->replyRaw($context->trans('info.mlock_modes', ['%modes%' => $modesDisplay]));
    }

    private function presentEnabledOptions(ChanServContext $context, ChannelInfoView $info): void
    {
        $enabledOptions = [];
        if ($info->topicLock) {
            $enabledOptions[] = 'TOPICLOCK';
        }
        if ($info->mlockActive) {
            $enabledOptions[] = 'MLOCK';
        }
        if ($info->secure) {
            $enabledOptions[] = 'SECURE';
        }
        if ($info->ircopOnly) {
            $enabledOptions[] = 'IRCOPONLY';
        }

        if ([] === $enabledOptions) {
            return;
        }

        $context->replyRaw($context->trans('info.options', ['%options%' => implode(', ', $enabledOptions)]));
    }

    private function presentNoExpire(ChanServContext $context, ChannelInfoView $info): void
    {
        if (!$info->noExpire) {
            return;
        }

        $context->replyRaw($context->trans('info.no_expire'));
    }

    private function presentOptionalDate(ChanServContext $context, string $key, ?DateTimeImmutable $date): void
    {
        if (null !== $date) {
            $context->replyRaw($context->trans($key, ['%date%' => $context->formatDate($date)]));
        }
    }

    private function presentOptionalLine(ChanServContext $context, string $key, string $placeholder, ?string $value): void
    {
        if (null === $value) {
            return;
        }

        $context->replyRaw($context->trans($key, [$placeholder => $value]));
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
