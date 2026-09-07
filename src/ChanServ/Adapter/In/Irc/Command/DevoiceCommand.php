<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;

/**
 * DEVOICE <#channel> <nickname>. ChanServ removes +v.
 */
final readonly class DevoiceCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private NetworkUserLookupPort $userLookup,
        private ChanServAccessHelper $accessHelper,
        private ChanUserAccountPort $accountPort,
    ) {}

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
        return 25;
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

        $validation = $this->validateDevoiceExecute($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $targetNick, $channel, $targetSender] = $validation;
        $context->getNotifier()->setChannelMemberMode($channelName, $targetSender->uid, 'v', false, $channel->getCreatedAt()->getTimestamp());
        $context->getNotifier()->sendNoticeToChannel(
            $channelName,
            $context->trans('voice.notice_grant', [
                '%from%' => $sender->nick,
                '%to%' => $targetNick,
                '%mode%' => '-v',
            ])
        );
        $context->reply('devoice.done', ['%nickname%' => $targetNick]);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateDevoiceExecute(ChanServContext $context): ?array
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

        return $this->validateDevoiceSender($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateDevoiceSender(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, $senderAccount->id, ChannelLevel::KEY_VOICEDEVOICE, $channelName, 'DEVOICE');
        }

        return $this->validateDevoiceTarget($context, $channel, $channelName, $targetNick, $senderAccount);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateDevoiceTarget(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick, ChanAccountView $senderAccount): ?array
    {
        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('voice.user_not_on_channel', ['%nickname%' => $targetNick]);

            return null;
        }
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, $senderAccount->id, true);
        $targetAccount = $this->accountPort->findAccountByNick($targetNick);
        if (null === $targetAccount) {
            $targetLevel = ChannelAccess::LEVEL_UNREGISTERED;
        } else {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->id, $targetSender->isIdentified);
        }
        $isSelfTarget = null !== $targetAccount && $targetAccount->id === $senderAccount->id;
        if (!$context->isLevelFounder && !$isSelfTarget && $senderLevel <= $targetLevel) {
            $context->reply('error.insufficient_access', ['%operation%' => 'DEVOICE', '%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $targetNick, $channel, $targetSender];
    }
}
