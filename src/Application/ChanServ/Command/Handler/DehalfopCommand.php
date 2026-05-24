<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * DEHALFOP <#channel> <nickname>. ChanServ removes +h. Only if IRCd supports halfop mode.
 */
final readonly class DehalfopCommand implements ChanServCommandInterface
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
        return 'DEHALFOP';
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
        return 'dehalfop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'dehalfop.help';
    }

    public function getOrder(): int
    {
        return 23;
    }

    public function getShortDescKey(): string
    {
        return 'dehalfop.short';
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

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return true;
    }

    public function execute(ChanServContext $context): void
    {
        if (!$context->getChannelModeSupport()->hasHalfOp()) {
            $context->reply('halfop.not_supported');

            return;
        }

        $validation = $this->validateDehalfopExecute($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $targetNick, $targetSender] = $validation;
        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'h', false);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('halfop.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '-h',
            ])
        );
        $context->reply('dehalfop.done', ['%nickname%' => $targetNick]);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDehalfopExecute(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return null;
        }

        $targetNick = $context->args[1] ?? '';
        if ('' === $targetNick) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        return $this->validateDehalfopSender($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDehalfopSender(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_HALFOPDEHALFOP, $channelName, 'DEHALFOP');
        }

        return $this->validateDehalfopTarget($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDehalfopTarget(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('halfop.user_not_on_channel', ['%nickname%' => $targetNick]);

            return null;
        }
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, $context->senderAccount->getId(), true);
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $targetLevel = ChannelAccess::LEVEL_UNREGISTERED;
        } else {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
        }
        $isSelfTarget = null !== $targetAccount && null !== $context->senderAccount && $targetAccount->getId() === $context->senderAccount->getId();
        if (!$context->isLevelFounder && !$isSelfTarget && $senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEHALFOP', '%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $targetNick, $targetSender];
    }
}
