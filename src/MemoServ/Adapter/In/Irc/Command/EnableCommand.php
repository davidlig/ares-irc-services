<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Enable\EnableMemos;
use App\MemoServ\Application\UseCase\Enable\EnableMemosHandlerInterface;
use App\MemoServ\Application\UseCase\Enable\EnableMemosOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function str_starts_with;

/**
 * ENABLE [#canal].
 * For nick: enable memo reception for own nick. For channel: founder only.
 */
final readonly class EnableCommand implements MemoServCommandInterface
{
    public function __construct(
        private EnableMemosHandlerInterface $enableMemosHandler,
    ) {}

    public function getName(): string
    {
        return 'ENABLE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('enable.syntax', [new Argument('channel', optional: true, prefix: '#')]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'enable.help';
    }

    public function getExampleKey(): string
    {
        return 'enable.example';
    }

    public function getOrder(): int
    {
        return 6;
    }

    public function getShortDescKey(): string
    {
        return 'enable.short';
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

    public function execute(MemoServContext $context): null
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount || null === $context->sender) {
            $context->replyError('error.not_identified');

            return null;
        }

        $first = $context->args[0] ?? null;
        $isChannel = null !== $first && str_starts_with($first, '#');
        $targetChannel = $isChannel ? $first : null;

        $result = $this->enableMemosHandler->handle(new EnableMemos(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
        ));

        switch ($result->outcome) {
            case EnableMemosOutcome::EnabledNick:
                $context->reply('enable.enabled_nick', [], Role::Success);
                break;

            case EnableMemosOutcome::AlreadyEnabledNick:
                $context->reply('enable.already_enabled_nick', [], Role::Information);
                break;

            case EnableMemosOutcome::EnabledChannel:
                $context->reply('enable.enabled_channel', ['channel' => $context->emphasize($result->channelName ?? '')], Role::Success);
                break;

            case EnableMemosOutcome::AlreadyEnabledChannel:
                $context->reply('enable.already_enabled_channel', ['channel' => $context->emphasize($result->channelName ?? '')], Role::Information);
                break;

            case EnableMemosOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case EnableMemosOutcome::FounderOnly:
                $context->reply('enable.founder_only', ['channel' => $context->emphasize($result->channelName ?? '')], Role::Error);
                break;
        }

        return null;
    }
}
