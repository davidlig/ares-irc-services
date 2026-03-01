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
 * VOICE <#channel> <nickname>.
 *
 * ChanServ gives +v. Requires VOICEDEVOICE level; SECURE: target must have access.
 */
final readonly class VoiceCommand implements ChanServCommandInterface
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
        return 'VOICE';
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
        return 'voice.syntax';
    }

    public function getHelpKey(): string
    {
        return 'voice.help';
    }

    public function getOrder(): int
    {
        return 22;
    }

    public function getShortDescKey(): string
    {
        return 'voice.short';
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

        $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_VOICEDEVOICE, $channelName, 'VOICE');

        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $targetNick]);

            return;
        }

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('voice.user_not_on_channel', ['%nick%' => $targetNick]);

            return;
        }

        if ($channel->isSecure()) {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId());
            if (0 === $targetLevel) {
                $context->reply('secure.requires_access', ['%nick%' => $targetNick]);

                return;
            }
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'v', true);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('voice.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '+v',
            ])
        );
        $context->reply('voice.done', ['%nick%' => $targetNick]);
    }
}
