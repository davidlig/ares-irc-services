<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\Unban\UnbanChannel;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelHandlerInterface;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function in_array;

/**
 * UNBAN <#channel>. Removes all ordinary bans matching the sender. Requires UNBAN level.
 */
final readonly class UnbanCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(private UnbanChannelHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'UNBAN';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'unban.syntax',
            arguments: [
                new Argument('channel'),
            ],
            allowExtra: false,
        );
    }

    public function getHelpKey(): string
    {
        return 'unban.help';
    }

    public function getExampleKey(): string
    {
        return 'unban.example';
    }

    public function getOrder(): int
    {
        return 11;
    }

    public function getShortDescKey(): string
    {
        return 'unban.short';
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
        if (!$this->validateArguments($context)) {
            return CommandOutcome::rejected();
        }
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        $account = $context->senderAccount;
        if (null === $account || (null !== $context->sender && !$context->sender->isIdentified)) {
            $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error);

            return CommandOutcome::rejected();
        }
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.generic', role: Role::Error);

            return CommandOutcome::rejected();
        }
        $result = $this->handler->handle(new UnbanChannel(
            channelName: $channelName,
            accountId: $account->id,
            requesterUid: $sender->uid,
            founderEquivalent: $context->isLevelFounder,
        ));
        $key = match ($result->outcome) {
            UnbanChannelOutcome::Removed => 'unban.done',
            UnbanChannelOutcome::NoMatches => 'unban.no_matches',
            UnbanChannelOutcome::ChannelNotOnNetwork => 'unban.not_on_network',
            UnbanChannelOutcome::Unavailable => 'unban.unavailable',
        };
        $role = match ($result->outcome) {
            UnbanChannelOutcome::Removed => Role::Success,
            UnbanChannelOutcome::NoMatches => Role::Information,
            UnbanChannelOutcome::ChannelNotOnNetwork, UnbanChannelOutcome::Unavailable => Role::Error,
        };
        $context->reply($key, ['%channel%' => $channelName, '%count%' => (string) $result->removedCount], $role);

        return in_array($result->outcome, [UnbanChannelOutcome::Removed, UnbanChannelOutcome::NoMatches], true) ? CommandOutcome::success() : CommandOutcome::rejected();
    }
}
