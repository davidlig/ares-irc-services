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

        if ($isChannel) {
            $channelName = $first;
            $indexArg = $context->args[1] ?? '';
            if ('' === $indexArg || !ctype_digit($indexArg)) {
                $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

                return;
            }
            $index = (int) $indexArg;
            $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
            if (null === $channel) {
                $context->reply('del.channel_not_registered', ['channel' => $channelName]);

                return;
            }
            $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_MEMOCHANGE, $channelName, 'DEL');
            $memo = $this->memoRepository->findByTargetChannelAndIndex($channel->getId(), $index);
        } else {
            $indexArg = $first;
            if (!ctype_digit($indexArg)) {
                $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

                return;
            }
            $index = (int) $indexArg;
            $memo = $this->memoRepository->findByTargetNickAndIndex($senderAccount->getId(), $index);
        }

        if (null === $memo) {
            $context->reply('del.not_found', ['index' => $index]);

            return;
        }

        $this->memoRepository->delete($memo);
        $context->reply('del.deleted', ['index' => $index]);
    }
}
