<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Disable\DisableMemos;
use App\MemoServ\Application\UseCase\Disable\DisableMemosHandlerInterface;
use App\MemoServ\Application\UseCase\Disable\DisableMemosOutcome;

use function str_starts_with;

/**
 * DISABLE [#canal].
 * For nick: disable memo reception for own nick. For channel: founder only.
 */
final readonly class DisableCommand implements MemoServCommandInterface
{
    public function __construct(
        private DisableMemosHandlerInterface $disableMemosHandler,
    ) {}

    public function getName(): string
    {
        return 'DISABLE';
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
        return 'disable.syntax';
    }

    public function getHelpKey(): string
    {
        return 'disable.help';
    }

    public function getOrder(): int
    {
        return 7;
    }

    public function getShortDescKey(): string
    {
        return 'disable.short';
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

        $result = $this->disableMemosHandler->handle(new DisableMemos(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
        ));

        switch ($result->outcome) {
            case DisableMemosOutcome::DisabledNick:
                $context->reply('disable.disabled_nick');
                break;

            case DisableMemosOutcome::AlreadyDisabledNick:
                $context->reply('disable.already_disabled_nick');
                break;

            case DisableMemosOutcome::DisabledChannel:
                $context->reply('disable.disabled_channel', ['channel' => $result->channelName ?? '']);
                break;

            case DisableMemosOutcome::AlreadyDisabledChannel:
                $context->reply('disable.already_disabled_channel', ['channel' => $result->channelName ?? '']);
                break;

            case DisableMemosOutcome::ChannelNotRegistered:
                $context->reply('disable.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case DisableMemosOutcome::FounderOnly:
                $context->reply('disable.founder_only', ['channel' => $result->channelName ?? '']);
                break;
        }

        return null;
    }
}
