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
 * HALFOP <#channel> <nickname>. ChanServ gives +h. Requires HALFOPDEHALFOP; only if IRCd supports halfop mode.
 */
final readonly class HalfopCommand implements ChanServCommandInterface
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
        return 'HALFOP';
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
        return 'halfop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'halfop.help';
    }

    public function getOrder(): int
    {
        return 25;
    }

    public function getShortDescKey(): string
    {
        return 'halfop.short';
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
        if (!$context->getChannelModeSupport()->hasHalfOp()) {
            $context->reply('halfop.not_supported');

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

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_HALFOPDEHALFOP, $channelName, 'HALFOP');

        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $targetNick]);

            return;
        }

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('halfop.user_not_on_channel', ['%nick%' => $targetNick]);

            return;
        }

        if ($channel->isSecure()) {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId());
            if (0 === $targetLevel) {
                $context->reply('secure.requires_access', ['%nick%' => $targetNick]);

                return;
            }
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'h', true);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('halfop.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '+h',
            ])
        );
        $context->reply('halfop.done', ['%nick%' => $targetNick]);
    }
}
