<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

/**
 * INVITE <#channel>. Invites the sender to the channel. Requires INVITE level.
 */
final readonly class InviteCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

    public function getName(): string
    {
        return 'INVITE';
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
        return 'invite.syntax';
    }

    public function getHelpKey(): string
    {
        return 'invite.help';
    }

    public function getOrder(): int
    {
        return 24;
    }

    public function getShortDescKey(): string
    {
        return 'invite.short';
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
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

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

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_INVITE, $channelName, 'INVITE');

        if (null === $context->sender) {
            $context->reply('error.generic');

            return;
        }

        $context->getNotifier()->inviteToChannel($channelName, $context->sender->uid);
        $context->reply('invite.done', ['%channel%' => $channelName]);

        $notice = $context->trans('invite.notice_channel', ['%nickname%' => $context->sender->nick]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }
}
