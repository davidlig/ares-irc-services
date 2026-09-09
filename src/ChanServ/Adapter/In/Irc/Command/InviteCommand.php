<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\PrepareInvite\PrepareChannelInvite;
use App\ChanServ\Application\UseCase\PrepareInvite\PrepareChannelInviteHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * INVITE <#channel>. Invites the sender to the channel. Requires INVITE level.
 */
final readonly class InviteCommand implements ChanServCommandInterface
{
    public function __construct(private PrepareChannelInviteHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'INVITE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'invite.syntax';
    }

    public function getHelpKey(): string
    {
        return 'invite.help';
    }

    public function getOrder(): int
    {
        return 10;
    }

    public function getShortDescKey(): string
    {
        return 'invite.short';
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }
        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return CommandOutcome::rejected();
        }
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.generic');

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new PrepareChannelInvite(
            channelName: $channelName,
            accountId: $account->id,
            founderEquivalent: $context->isLevelFounder,
        ));
        $context->getNotifier()->inviteToChannel($channelName, $sender->uid, $result->channelCreationTimestamp);
        $context->reply('invite.done', ['%channel%' => $channelName]);
        $notice = $context->trans('invite.notice_channel', ['%nickname%' => $sender->nick]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);

        return CommandOutcome::success();
    }
}
