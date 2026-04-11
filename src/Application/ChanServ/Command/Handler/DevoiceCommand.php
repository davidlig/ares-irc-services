<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * DEVOICE <#channel> <nickname>. ChanServ removes +v.
 */
final readonly class DevoiceCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private NetworkUserLookupPort $userLookup,
        private ChanServAccessHelper $accessHelper,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'DEVOICE';
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
        return 'devoice.syntax';
    }

    public function getHelpKey(): string
    {
        return 'devoice.help';
    }

    public function getOrder(): int
    {
        return 23;
    }

    public function getShortDescKey(): string
    {
        return 'devoice.short';
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

    public function allowsSuspendedChannel(): bool
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

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_VOICEDEVOICE, $channelName, 'DEVOICE');

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('voice.user_not_on_channel', ['%nickname%' => $targetNick]);

            return;
        }
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, $senderAccount->getId());
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $targetLevel = ChannelAccess::LEVEL_UNREGISTERED;
        } else {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
        }
        $isSelfTarget = null !== $targetAccount && null !== $senderAccount && $targetAccount->getId() === $senderAccount->getId();
        if (!$isSelfTarget && $senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEVOICE', '%channel%' => $channelName]);

            return;
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'v', false);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('voice.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '-v',
            ])
        );
        $context->reply('devoice.done', ['%nickname%' => $targetNick]);
    }
}
