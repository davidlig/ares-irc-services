<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServContext;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\MemoIgnore;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function in_array;
use function str_starts_with;
use function strtolower;
use function strtoupper;

/**
 * IGNORE {ADD|DEL|LIST} [#canal] [nick].
 * For nick: IGNORE ADD nick, IGNORE DEL nick, IGNORE LIST.
 * For channel: IGNORE ADD #chan nick, IGNORE DEL #chan nick, IGNORE LIST #chan (requires MEMOCHANGE for ADD/DEL).
 */
final readonly class IgnoreCommand implements MemoServCommandInterface
{
    private const array SUBCOMMANDS = ['ADD', 'DEL', 'LIST'];

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

    public function getName(): string
    {
        return 'IGNORE';
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
        return 'ignore.syntax';
    }

    public function getHelpKey(): string
    {
        return 'ignore.help';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'ignore.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => 'ignore.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'ignore.del.short', 'help_key' => 'ignore.del.help', 'syntax_key' => 'ignore.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'ignore.list.short', 'help_key' => 'ignore.list.help', 'syntax_key' => 'ignore.list.syntax'],
        ];
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

        $sub = strtoupper($context->args[0] ?? '');
        if (!in_array($sub, self::SUBCOMMANDS, true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $arg1 = $context->args[1] ?? null;
        $arg2 = $context->args[2] ?? null;

        $isChannel = null !== $arg1 && str_starts_with($arg1, '#');
        if ($isChannel) {
            $channelName = $arg1;
            $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
            if (null === $channel) {
                $context->reply('ignore.channel_not_registered', ['channel' => $channelName]);

                return;
            }
            if ('LIST' !== $sub) {
                $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_MEMOCHANGE, $channelName, 'IGNORE');
            }
            $targetNickId = null;
            $targetChannelId = $channel->getId();
        } else {
            $targetNickId = $senderAccount->getId();
            $targetChannelId = null;
        }

        if ('LIST' === $sub) {
            $this->doList($context, $targetNickId, $targetChannelId);

            return;
        }

        $nickToIgnore = $isChannel ? ($arg2 ?? '') : ($arg1 ?? '');
        if ('' === $nickToIgnore) {
            $context->reply('error.syntax', ['syntax' => $context->trans('ignore.' . strtolower($sub) . '.syntax')]);

            return;
        }

        $ignoredAccount = $this->nickRepository->findByNick($nickToIgnore);
        if (null === $ignoredAccount) {
            $context->reply('ignore.nick_not_registered', ['nick' => $nickToIgnore]);

            return;
        }

        if ('ADD' === $sub) {
            $this->doAdd($context, $targetNickId, $targetChannelId, $ignoredAccount->getId(), $nickToIgnore);
        } else {
            $this->doDel($context, $targetNickId, $targetChannelId, $ignoredAccount->getId(), $nickToIgnore);
        }
    }

    private function doAdd(MemoServContext $context, ?int $targetNickId, ?int $targetChannelId, int $ignoredNickId, string $nickDisplay): void
    {
        $existing = null !== $targetChannelId
            ? $this->memoIgnoreRepository->findByTargetChannelAndIgnored($targetChannelId, $ignoredNickId)
            : $this->memoIgnoreRepository->findByTargetNickAndIgnored($targetNickId, $ignoredNickId);
        if (null !== $existing) {
            $context->reply('ignore.already_ignored', ['nick' => $nickDisplay]);

            return;
        }
        $ignore = new MemoIgnore($targetNickId, $targetChannelId, $ignoredNickId);
        $this->memoIgnoreRepository->save($ignore);
        $context->reply('ignore.added', ['nick' => $nickDisplay]);
    }

    private function doDel(MemoServContext $context, ?int $targetNickId, ?int $targetChannelId, int $ignoredNickId, string $nickDisplay): void
    {
        $existing = null !== $targetChannelId
            ? $this->memoIgnoreRepository->findByTargetChannelAndIgnored($targetChannelId, $ignoredNickId)
            : $this->memoIgnoreRepository->findByTargetNickAndIgnored($targetNickId, $ignoredNickId);
        if (null === $existing) {
            $context->reply('ignore.not_ignored', ['nick' => $nickDisplay]);

            return;
        }
        $this->memoIgnoreRepository->delete($existing);
        $context->reply('ignore.removed', ['nick' => $nickDisplay]);
    }

    private function doList(MemoServContext $context, ?int $targetNickId, ?int $targetChannelId): void
    {
        $list = null !== $targetChannelId
            ? $this->memoIgnoreRepository->listByTargetChannel($targetChannelId)
            : $this->memoIgnoreRepository->listByTargetNick($targetNickId);
        if ([] === $list) {
            $context->reply('ignore.list_empty');

            return;
        }
        $context->reply('ignore.list_header');
        foreach ($list as $ignore) {
            if (!$ignore instanceof MemoIgnore) {
                continue;
            }
            $nick = $this->nickRepository->findById($ignore->getIgnoredNickId());
            $name = null !== $nick ? $nick->getNickname() : (string) $ignore->getIgnoredNickId();
            $context->replyRaw('  ' . $name);
        }
        $context->reply('ignore.list_footer');
    }
}
