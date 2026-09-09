<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Enable\EnableMemos;
use App\MemoServ\Application\UseCase\Enable\EnableMemosHandlerInterface;
use App\MemoServ\Application\UseCase\Enable\EnableMemosOutcome;

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

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'enable.syntax';
    }

    public function getHelpKey(): string
    {
        return 'enable.help';
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
            $context->reply('error.not_identified');

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
                $context->reply('enable.enabled_nick');
                break;

            case EnableMemosOutcome::AlreadyEnabledNick:
                $context->reply('enable.already_enabled_nick');
                break;

            case EnableMemosOutcome::EnabledChannel:
                $context->reply('enable.enabled_channel', ['channel' => $result->channelName ?? '']);
                break;

            case EnableMemosOutcome::AlreadyEnabledChannel:
                $context->reply('enable.already_enabled_channel', ['channel' => $result->channelName ?? '']);
                break;

            case EnableMemosOutcome::ChannelNotRegistered:
                $context->reply('enable.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case EnableMemosOutcome::FounderOnly:
                $context->reply('enable.founder_only', ['channel' => $result->channelName ?? '']);
                break;
        }

        return null;
    }
}
