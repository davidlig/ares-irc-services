<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Read\ReadMemo;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Read\ReadMemoOutcome;
use DateTimeImmutable;

use function ctype_digit;
use function str_starts_with;

/**
 * READ [#canal] <número>.
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 */
final readonly class ReadCommand implements MemoServCommandInterface
{
    public function __construct(
        private ReadMemoHandlerInterface $readMemoHandler,
    ) {}

    public function getName(): string
    {
        return 'READ';
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
        return 'read.syntax';
    }

    public function getHelpKey(): string
    {
        return 'read.help';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'read.short';
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

        $first = $context->args[0] ?? '';
        $isChannel = str_starts_with($first, '#');
        $targetChannel = $isChannel ? $first : null;
        $indexArg = $isChannel ? ($context->args[1] ?? '') : $first;

        if ('' === $indexArg || !ctype_digit($indexArg)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        $index = (int) $indexArg;

        $result = $this->readMemoHandler->handle(new ReadMemo(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
            index: $index,
            occurredAt: new DateTimeImmutable(),
        ));

        switch ($result->outcome) {
            case ReadMemoOutcome::Success:
                $context->reply('read.header', ['index' => $result->index, 'from' => $result->from ?? '']);
                $context->replyRaw(' ' . ($result->message ?? ''));
                $context->reply('read.footer', ['date' => $context->formatDate($result->createdAt)]);
                break;

            case ReadMemoOutcome::NotFound:
                $context->reply('read.not_found', ['index' => $result->index]);
                break;

            case ReadMemoOutcome::ChannelNotRegistered:
                $context->reply('read.channel_not_registered', ['channel' => $targetChannel ?? '']);
                break;

            case ReadMemoOutcome::AccessDenied:
                $context->reply('error.insufficient_access', [
                    'operation' => 'READ',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;
        }

        return null;
    }
}
