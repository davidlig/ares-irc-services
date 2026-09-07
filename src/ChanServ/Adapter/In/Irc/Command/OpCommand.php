<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Application\Port\In\NetworkUserLookupPort;

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
        private ChanUserAccountPort $accountPort,
        private NetworkUserLookupPort $userLookup,
    ) {}

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

    public function getRequiredPermission(): string
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
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $validation = $this->validateOpExecute($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $targetNick, $channel, $targetUid] = $validation;
        $context->getNotifier()->setChannelMemberMode($channelName, $targetUid, 'o', true, $channel->getCreatedAt()->getTimestamp());
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('op.notice_grant', [
                '%from%' => $sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '+o',
            ])
        );
        $context->reply('op.done', ['%nickname%' => $targetNick]);
    }

    /** @return array{string, string, RegisteredChannel, string}|null */
    private function validateOpExecute(ChanServContext $context): ?array
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

        return $this->validateOpSender($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, RegisteredChannel, string}|null */
    private function validateOpSender(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        if (!$context->isLevelFounder) {
            $requiredLevel = $this->getLevelValue($channel->getId(), ChannelLevel::KEY_OPDEOP);
            $senderLevel = $this->effectiveAccessLevel($channel, $senderAccount->id, true);
            if ($senderLevel < $requiredLevel) {
                throw InsufficientAccessException::forOperation($channelName, 'OP');
            }
        }

        $targetAccount = $this->accountPort->findAccountByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $targetNick]);

            return null;
        }

        return $this->validateOpTarget($context, $channel, $channelName, $targetNick, $targetAccount);
    }

    /** @return array{string, string, RegisteredChannel, string}|null */
    private function validateOpTarget(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick, ChanAccountView $targetAccount): ?array
    {
        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('op.user_not_on_channel', ['%nickname%' => $targetNick]);

            return null;
        }
        $targetUid = $targetSender->uid;

        if (!$context->isLevelFounder && $channel->isSecure()) {
            $targetLevel = $this->effectiveAccessLevel($channel, $targetAccount->id, $targetSender->isIdentified);
            $minLevelForMode = $this->getLevelValue($channel->getId(), ChannelLevel::KEY_AUTOOP);
            if ($targetLevel < $minLevelForMode) {
                $context->reply('secure.requires_min_level', [
                    '%nickname%' => $targetNick,
                    '%level%' => (string) $minLevelForMode,
                    '%mode%' => '+o',
                ]);

                return null;
            }
        }

        return [$channelName, $targetNick, $channel, $targetUid];
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
