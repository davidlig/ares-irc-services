<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Del\DelMemo;
use App\MemoServ\Application\UseCase\Del\DelMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Del\DelMemoOutcome;

use function ctype_digit;
use function str_starts_with;

/**
 * DEL [#canal] <número>.
 * Delete a memo by its 1-based index. For channel, requires MEMOCHANGE.
 */
final readonly class DelCommand implements MemoServCommandInterface
{
    public function __construct(
        private DelMemoHandlerInterface $delMemoHandler,
    ) {}

    public function getName(): string
    {
        return 'DEL';
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
        return 'del.syntax';
    }

    public function getHelpKey(): string
    {
        return 'del.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'del.short';
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

    public function execute(MemoServContext $context): void
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount || null === $context->sender) {
            $context->reply('error.not_identified');

            return;
        }

        $first = $context->args[0] ?? '';
        $isChannel = str_starts_with($first, '#');
        $targetChannel = $isChannel ? $first : null;
        $indexArg = $isChannel ? ($context->args[1] ?? '') : $first;

        if ('' === $indexArg || !ctype_digit($indexArg)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $index = (int) $indexArg;

        $result = $this->delMemoHandler->handle(new DelMemo(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
            index: $index,
        ));

        switch ($result->outcome) {
            case DelMemoOutcome::Deleted:
                $context->reply('del.deleted', ['index' => $result->index]);
                break;

            case DelMemoOutcome::NotFound:
                $context->reply('del.not_found', ['index' => $result->index]);
                break;

            case DelMemoOutcome::ChannelNotRegistered:
                $context->reply('del.channel_not_registered', ['channel' => $targetChannel ?? '']);
                break;
        }
    }
}
