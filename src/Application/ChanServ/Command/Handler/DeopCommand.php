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
    ) {}

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
        $validation = $this->validateDeopExecute($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $targetNick, $targetSender] = $validation;
        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'o', false);
        $context->getNotifier()->sendNoticeToChannel($channelName, $context->trans('op.notice_grant', ['%from%' => $context->sender->nick, '%to%' => $targetNick, '%mode%' => '-o']));
        $context->reply('deop.done', ['%nickname%' => $targetNick]);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDeopExecute(ChanServContext $context): ?array
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

        return $this->validateDeopSender($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDeopSender(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }
        if (!$context->isLevelFounder) {
            $requiredLevel = $this->getLevelValue($channel->getId(), ChannelLevel::KEY_OPDEOP);
            $senderLevel = $this->effectiveAccessLevel($channel, $senderAccount->getId(), true);
            if ($senderLevel < $requiredLevel) {
                throw InsufficientAccessException::forOperation($channelName, 'DEOP');
            }
        }

        return $this->validateDeopTarget($context, $channel, $channelName, $targetNick, $senderLevel);
    }

    /** @return array{string, string, SenderView}|null */
    private function validateDeopTarget(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick, int $senderLevel): ?array
    {
        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('op.user_not_on_channel', ['%nickname%' => $targetNick]);

            return null;
        }
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $targetLevel = ChannelAccess::LEVEL_UNREGISTERED;
        } else {
            $targetLevel = $this->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
        }
        $isSelfTarget = null !== $targetAccount && null !== $context->senderAccount && $targetAccount->getId() === $context->senderAccount->getId();
        if (!$context->isLevelFounder && !$isSelfTarget && $senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEOP', '%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $targetNick, $targetSender];
    }

    private function getLevelValue(int $channelId, string $key): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, $key);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault($key);
    }

    private function effectiveAccessLevel(RegisteredChannel $channel, int $nickId, bool $isIdentified = false): int
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
