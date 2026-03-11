<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServContext;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * LIST [#canal].
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 * Unread shown with red asterisk and preview (max 50 chars).
 */
final readonly class ListCommand implements MemoServCommandInterface
{
    private const int PREVIEW_MAX_LENGTH = 50;

    /** IRC red */
    private const string RED = "\x0304";

    /** IRC blue (sender nick) */
    private const string BLUE = "\x0302";

    private const string RESET = "\x03";

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoRepositoryInterface $memoRepository,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

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

    public function getRequiredPermission(): ?string
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

        if ($isChannel) {
            $channelName = $first;
            $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
            if (null === $channel) {
                $context->reply('list.channel_not_registered', ['channel' => $channelName]);

                return;
            }
            $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_MEMOREAD, $channelName, 'LIST');
            $memos = $this->memoRepository->findByTargetChannel($channel->getId());
            $targetLabel = $channelName;
        } else {
            $memos = $this->memoRepository->findByTargetNick($senderAccount->getId());
            $targetLabel = $senderAccount->getNickname();
        }

        if ([] === $memos) {
            $context->reply('list.empty', ['target' => $targetLabel]);

            return;
        }

        $context->reply('list.header', ['target' => $targetLabel]);
        $index = 1;
        foreach ($memos as $memo) {
            if (!$memo instanceof Memo) {
                continue;
            }
            $senderNick = $this->getSenderNickDisplay($memo);
            $dateStr = $context->formatDate($memo->getCreatedAt());
            $preview = $this->preview($memo->getMessage());
            $unreadMark = $memo->isRead() ? '' : self::RED . '*' . self::RESET . ' ';
            $context->replyRaw(sprintf('  %s#%d ' . self::BLUE . '%s' . self::RESET . ' (%s): %s', $unreadMark, $index, $senderNick, $dateStr, $preview));
            ++$index;
        }
        $context->reply('list.footer');
    }

    private function getSenderNickDisplay(Memo $memo): string
    {
        $sender = $this->nickRepository->findById($memo->getSenderNickId());

        return null !== $sender ? $sender->getNickname() : (string) $memo->getSenderNickId();
    }

    private function preview(string $message): string
    {
        $message = str_replace(["\r", "\n"], ' ', $message);
        if (mb_strlen($message) <= self::PREVIEW_MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::PREVIEW_MAX_LENGTH) . '…';
    }
}
