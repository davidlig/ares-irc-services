<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
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
        if (!$context->getChannelModeSupport()->hasAdmin()) {
            $context->reply('admin.not_supported');

            return;
        }

        $validation = $this->validateAdminExecute($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $targetNick, $channel, $targetSender] = $validation;
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

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateAdminExecute(ChanServContext $context): ?array
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

        return $this->validateAdminSender($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateAdminSender(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_ADMINDEADMIN, $channelName, 'ADMIN');
        }

        return $this->validateAdminTarget($context, $channel, $channelName, $targetNick);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateAdminTarget(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick): ?array
    {
        $targetAccount = $this->nickRepository->findByNick($targetNick);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $targetNick]);

            return null;
        }

        $targetSender = $this->userLookup->findByNick($targetNick);
        if (null === $targetSender) {
            $context->reply('admin.user_not_on_channel', ['%nickname%' => $targetNick]);

            return null;
        }

        return $this->validateAdminSecureCheck($context, $channel, $channelName, $targetNick, $targetAccount, $targetSender);
    }

    /** @return array{string, string, RegisteredChannel, SenderView}|null */
    private function validateAdminSecureCheck(ChanServContext $context, RegisteredChannel $channel, string $channelName, string $targetNick, $targetAccount, SenderView $targetSender): ?array
    {
        if (!$context->isLevelFounder && $channel->isSecure()) {
            $targetLevel = $this->accessHelper->effectiveAccessLevel($channel, $targetAccount->getId(), $targetSender->isIdentified);
            $minLevelForMode = $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_AUTOADMIN);
            if ($targetLevel < $minLevelForMode) {
                $context->reply('secure.requires_min_level', [
                    '%nickname%' => $targetNick,
                    '%level%' => (string) $minLevelForMode,
                    '%mode%' => '+a',
                ]);

                return null;
            }
        }

        return [$channelName, $targetNick, $channel, $targetSender];
    }
}
