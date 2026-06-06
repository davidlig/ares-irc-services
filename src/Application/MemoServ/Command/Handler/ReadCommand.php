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

use function ctype_digit;
use function str_starts_with;
use function strtolower;

/**
 * READ [#canal] <número>.
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 */
final readonly class ReadCommand implements MemoServCommandInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoRepositoryInterface $memoRepository,
        private ChanServAccessHelper $accessHelper,
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

        $first = $context->args[0] ?? '';
        $isChannel = str_starts_with($first, '#');

        $memoResult = $isChannel
            ? $this->resolveChannelMemo($context, $senderAccount->getId(), $first)
            : $this->resolveNickMemo($context, $senderAccount->getId(), $first);

        if (null === $memoResult) {
            return;
        }

        [$memo, $index] = $memoResult;
        $memo->markAsRead();
        $this->memoRepository->save($memo);

        $this->displayMemo($context, $memo, $index);
    }

    private function resolveChannelMemo(MemoServContext $context, int $senderNickId, string $channelName): ?array
    {
        $indexArg = $context->args[1] ?? '';
        if ('' === $indexArg || !ctype_digit($indexArg)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }
        $index = (int) $indexArg;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            $context->reply('read.channel_not_registered', ['channel' => $channelName]);

            return null;
        }
        $this->accessHelper->requireLevel($channel, $senderNickId, ChannelLevel::KEY_MEMOREAD, $channelName, 'READ');
        $memo = $this->memoRepository->findByTargetChannelAndIndex($channel->getId(), $index);

        $result = null;
        if (null !== $memo) {
            $result = [$memo, $index];
        } else {
            $context->reply('read.not_found', ['index' => $index]);
        }

        return $result;
    }

    private function resolveNickMemo(MemoServContext $context, int $senderNickId, string $indexArg): ?array
    {
        if (!ctype_digit($indexArg)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }
        $index = (int) $indexArg;
        $memo = $this->memoRepository->findByTargetNickAndIndex($senderNickId, $index);

        $result = null;
        if (null !== $memo) {
            $result = [$memo, $index];
        } else {
            $context->reply('read.not_found', ['index' => $index]);
        }

        return $result;
    }

    private function displayMemo(MemoServContext $context, Memo $memo, int $index): void
    {
        $senderNick = $this->nickRepository->findById($memo->getSenderNickId());
        $from = null !== $senderNick ? $senderNick->getNickname() : (string) $memo->getSenderNickId();
        $context->reply('read.header', ['index' => $index, 'from' => $from]);
        $context->replyRaw(' ' . $memo->getMessage());
        $context->reply('read.footer', ['date' => $context->formatDate($memo->getCreatedAt())]);
    }
}
