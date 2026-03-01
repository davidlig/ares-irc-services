<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * DEADMIN <#channel> <nickname>. ChanServ removes +a. Only if IRCd supports admin mode.
 */
final readonly class DeadminCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private NetworkUserLookupPort $userLookup,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

    public function getName(): string
    {
        return 'DEADMIN';
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
        return 'deadmin.syntax';
    }

    public function getHelpKey(): string
    {
        return 'deadmin.help';
    }

    public function getOrder(): int
    {
        return 19;
    }

    public function getShortDescKey(): string
    {
        return 'deadmin.short';
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
        return 'IDENTIFIED';
    }

    public function execute(ChanServContext $context): void
    {
        if (!$context->getChannelModeSupport()->hasAdmin()) {
            $context->reply('admin.not_supported');

            return;
        }

        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $targetNick = $context->args[1] ?? '';
        if ('' === $targetNick) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return;
        }

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_ADMINDEADMIN, $channelName, 'DEADMIN');

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('admin.user_not_on_channel', ['%nick%' => $targetNick]);

            return;
        }
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, $senderAccount->getId());
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        $targetLevel = null === $targetAccount ? 0 : $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId());
        if ($senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEADMIN', '%channel%' => $channelName]);

            return;
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'a', false);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('admin.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '-a',
            ])
        );
        $context->reply('deadmin.done', ['%nick%' => $targetNick]);
    }
}
