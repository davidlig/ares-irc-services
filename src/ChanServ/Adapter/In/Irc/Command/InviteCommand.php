<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\PrepareInvite\PrepareChannelInvite;
use App\ChanServ\Application\UseCase\PrepareInvite\PrepareChannelInviteHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

/**
 * INVITE <#channel>. Invites the sender to the channel. Requires INVITE level.
 */
final readonly class InviteCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(private PrepareChannelInviteHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'INVITE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'invite.syntax',
            arguments: [
                new Argument('channel'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'invite.help';
    }

    public function getExampleKey(): string
    {
        return 'invite.example';
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
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error);

            return CommandOutcome::rejected();
        }
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.generic', role: Role::Error);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new PrepareChannelInvite(
            channelName: $channelName,
            accountId: $account->id,
            founderEquivalent: $context->isLevelFounder,
        ));
        $context->getNotifier()->inviteToChannel($channelName, $sender->uid, $result->channelCreationTimestamp);
        $context->reply('invite.done', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success);
        $notice = $context->renderReply(Role::Success, 'invite.notice_channel', ['%nickname%' => new Fragment($sender->nick, Component::Actor)]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);

        return CommandOutcome::success();
    }
}
