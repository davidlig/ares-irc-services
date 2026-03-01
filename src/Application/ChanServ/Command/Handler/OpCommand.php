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
 * OP <#channel> <nickname>.
 *
 * ChanServ gives +o to the user. Requires OPDEOP level; SECURE: target must have access.
 */
final readonly class OpCommand implements ChanServCommandInterface
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
        return 'OP';
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
        return 'op.syntax';
    }

    public function getHelpKey(): string
    {
        return 'op.help';
    }

    public function getOrder(): int
    {
        return 20;
    }

    public function getShortDescKey(): string
    {
        return 'op.short';
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
            throw InsufficientAccessException::forOperation($channelName, 'OP');
        }

        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $targetNick]);

            return;
        }

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('op.user_not_on_channel', ['%nick%' => $targetNick]);

            return;
        }
        $targetUid = $targetSender->uid;

        if ($channel->isSecure()) {
            $targetLevel = $this->effectiveAccessLevel($channel, $targetAccount->getId());
            if (0 === $targetLevel) {
                $context->reply('secure.requires_access', ['%nick%' => $targetNick]);

                return;
            }
        }

        $context->getNotifier()->setChannelMemberMode($channelName, $targetUid, 'o', true);
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('op.notice_grant', [
                '%from%' => $context->sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '+o',
            ])
        );
        $context->reply('op.done', ['%nick%' => $targetNick]);
    }

    private function getLevelValue(int $channelId, string $key): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, $key);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault($key);
    }

    private function effectiveAccessLevel(RegisteredChannel $channel, int $nickId): int
    {
        if ($channel->isFounder($nickId)) {
            return ChannelAccess::FOUNDER_LEVEL;
        }
        $access = $this->accessRepository->findByChannelAndNick($channel->getId(), $nickId);

        return null !== $access ? $access->getLevel() : 0;
    }
}
