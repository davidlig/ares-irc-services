<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\List\ListMemos;
use App\MemoServ\Application\UseCase\List\ListMemosHandlerInterface;
use App\MemoServ\Application\UseCase\List\ListMemosOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function mb_strlen;
use function mb_substr;
use function str_replace;
use function str_starts_with;

/**
 * LIST [#canal].
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 * Unread shown with red asterisk and preview (max 50 chars).
 */
final readonly class ListCommand implements MemoServCommandInterface
{
    private const int PREVIEW_MAX_LENGTH = 50;

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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('list.syntax', [new Argument('channel', optional: true, prefix: '#')]);
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
        return 'list.help';
    }

    public function getExampleKey(): string
    {
        return 'list.example';
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

        $result = $this->listMemosHandler->handle(new ListMemos(
            senderNickId: $senderAccount->id,
            senderNickName: $context->sender->nick,
            channelName: $targetChannel,
        ));

        switch ($result->outcome) {
            case ListMemosOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case ListMemosOutcome::AccessDenied:
                $context->replyError('error.insufficient_access', [
                    'operation' => 'LIST',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;

            case ListMemosOutcome::Empty:
                $context->reply('list.empty', ['target' => $context->emphasize($result->targetLabel)], Role::Information);
                break;

            case ListMemosOutcome::Success:
                $context->replyComponent(Component::TableHeader, 'list.header', ['target' => $result->targetLabel]);
                foreach ($result->items as $item) {
                    $unreadMark = $item->isRead ? '' : $context->renderComponent(Component::Unread, ' ');
                    $dateStr = $context->formatDate($item->createdAt);
                    $context->replyRaw($context->renderComponent(Component::Row, '  %unread%#%index% %sender% (%date%): %message%', [
                        '%unread%' => $unreadMark,
                        '%index%' => (string) $item->index,
                        '%sender%' => new Fragment($item->senderDisplay, Component::Emphasis),
                        '%date%' => $dateStr,
                        '%message%' => self::preview($item->message),
                    ]));
                }
                $context->replySeparator();
                break;
        }

        return null;
    }

    private static function preview(string $message): string
    {
        $message = str_replace(["\r", "\n"], ' ', $message);
        if (mb_strlen($message) <= self::PREVIEW_MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::PREVIEW_MAX_LENGTH) . '…';
    }
}
