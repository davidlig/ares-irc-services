<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * DEOP <#channel> <nickname>. ChanServ removes +o.
 */
final readonly class DeopCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private NetworkUserLookupPort $userLookup,
    ) {
    }

    public function getName(): string
    {
        return 'DEOP';
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
        return 'deop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'deop.help';
    }

    public function getOrder(): int
    {
        return 21;
    }

    public function getShortDescKey(): string
    {
        return 'deop.short';
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
        $requiredLevel = $this->getLevelValue($channel->getId(), ChannelLevel::KEY_OPDEOP);
        $senderLevel = $this->effectiveAccessLevel($channel, $senderAccount->getId());
        if ($senderLevel < $requiredLevel) {
            throw InsufficientAccessException::forOperation($channelName, 'DEOP');
        }
        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('op.user_not_on_channel', ['%nick%' => $targetNick]);

            return;
        }
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $targetLevel = ChannelAccess::LEVEL_UNREGISTERED;
        } else {
            $targetLevel = $this->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
        }
        $isSelfTarget = null !== $targetAccount && null !== $senderAccount && $targetAccount->getId() === $senderAccount->getId();
        if (!$isSelfTarget && $senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEOP', '%channel%' => $channelName]);

            return;
        }
        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'o', false);
        $context->getNotifier()->sendNoticeToChannel($channelName, $context->trans('op.notice_grant', ['%from%' => $context->sender->nick, '%to%' => $targetNick, '%mode%' => '-o']));
        $context->reply('deop.done', ['%nick%' => $targetNick]);
    }

    private function getLevelValue(int $channelId, string $key): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, $key);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault($key);
    }

    private function effectiveAccessLevel(RegisteredChannel $channel, int $nickId, bool $isIdentified = true): int
    {
        if (!$isIdentified) {
            return ChannelAccess::LEVEL_UNREGISTERED;
        }
        if ($channel->isFounder($nickId)) {
            return ChannelAccess::FOUNDER_LEVEL;
        }
        $access = $this->accessRepository->findByChannelAndNick($channel->getId(), $nickId);

        return null !== $access ? $access->getLevel() : 0;
    }
}
