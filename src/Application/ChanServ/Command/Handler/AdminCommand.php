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
 * ADMIN <#channel> <nickname>. ChanServ gives +a. Requires ADMINDEADMIN; only if IRCd supports admin mode.
 */
final readonly class AdminCommand implements ChanServCommandInterface
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
        return 'ADMIN';
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
        return 'admin.syntax';
    }

    public function getHelpKey(): string
    {
        return 'admin.help';
    }

    public function getOrder(): int
    {
        return 18;
    }

    public function getShortDescKey(): string
    {
        return 'admin.short';
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

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_ADMINDEADMIN, $channelName, 'ADMIN');

        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('admin.user_not_on_channel', ['%nickname%' => $targetNick]);

            return;
        }

        if ($channel->isSecure()) {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
            $minLevelForMode = $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_AUTOADMIN);
            if ($targetLevel < $minLevelForMode) {
                $context->reply('secure.requires_min_level', [
                    '%nickname%' => $targetNick,
                    '%level%' => (string) $minLevelForMode,
                    '%mode%' => '+a',
                ]);

                return;
            }
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'a', true);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('admin.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '+a',
            ])
        );
        $context->reply('admin.done', ['%nickname%' => $targetNick]);
    }
}
