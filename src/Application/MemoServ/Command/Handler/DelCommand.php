<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServContext;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;

use function ctype_digit;
use function str_starts_with;
use function strtolower;

/**
 * DEL [#canal] <número>.
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOCHANGE).
 */
final readonly class DelCommand implements MemoServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoRepositoryInterface $memoRepository,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

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
        $this->memoRepository->delete($memo);
        $context->reply('del.deleted', ['index' => $index]);
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
            $context->reply('del.channel_not_registered', ['channel' => $channelName]);

            return null;
        }
        $this->accessHelper->requireLevel($channel, $senderNickId, ChannelLevel::KEY_MEMOCHANGE, $channelName, 'DEL');
        $memo = $this->memoRepository->findByTargetChannelAndIndex($channel->getId(), $index);

        $result = null;
        if (null !== $memo) {
            $result = [$memo, $index];
        } else {
            $context->reply('del.not_found', ['index' => $index]);
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
            $context->reply('del.not_found', ['index' => $index]);
        }

        return $result;
    }
}
