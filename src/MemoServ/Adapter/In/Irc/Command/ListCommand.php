<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\List\ListMemos;
use App\MemoServ\Application\UseCase\List\ListMemosHandlerInterface;
use App\MemoServ\Application\UseCase\List\ListMemosOutcome;

use function sprintf;
use function str_starts_with;

/**
 * LIST [#canal].
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 * Unread shown with red asterisk and preview (max 50 chars).
 */
final readonly class ListCommand implements MemoServCommandInterface
{
    /** IRC red */
    private const string RED = "\x0304";

    /** IRC blue (sender nick) */
    private const string BLUE = "\x0302";

    private const string RESET = "\x03";

    public function __construct(
        private ListMemosHandlerInterface $listMemosHandler,
    ) {}

    public function getName(): string
    {
        return 'LIST';
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
        return 'list.syntax';
    }

    public function getHelpKey(): string
    {
        return 'list.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'list.short';
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

        $first = $context->args[0] ?? null;
        $isChannel = null !== $first && str_starts_with($first, '#');
        $targetChannel = $isChannel ? $first : null;

        $result = $this->listMemosHandler->handle(new ListMemos(
            senderNickId: $senderAccount->id,
            senderNickName: $context->sender->nick,
            channelName: $targetChannel,
        ));

        switch ($result->outcome) {
            case ListMemosOutcome::ChannelNotRegistered:
                $context->reply('list.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case ListMemosOutcome::Empty:
                $context->reply('list.empty', ['target' => $result->targetLabel]);
                break;

            case ListMemosOutcome::Success:
                $context->reply('list.header', ['target' => $result->targetLabel]);
                foreach ($result->items as $item) {
                    $unreadMark = $item->isRead ? '' : self::RED . '*' . self::RESET . ' ';
                    $dateStr = $context->formatDate($item->createdAt);
                    $context->replyRaw(sprintf('  %s#%d ' . self::BLUE . '%s' . self::RESET . ' (%s): %s', $unreadMark, $item->index, $item->senderDisplay, $dateStr, $item->preview));
                }
                $context->reply('list.footer');
                break;
        }
    }
}
