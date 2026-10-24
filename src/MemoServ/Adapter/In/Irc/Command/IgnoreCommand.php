<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemo;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoAction;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoOutcome;

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
        private IgnoreMemoHandlerInterface $ignoreMemoHandler,
        private int $ignoreListLimitNick,
        private int $ignoreListLimitChannel,
    ) {}

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

        $sub = strtoupper($context->args[0] ?? '');
        if (!in_array($sub, self::SUBCOMMANDS, true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        if ('LIST' === $sub) {
            $arg1 = $context->args[1] ?? null;
            $targetChannel = null !== $arg1 && str_starts_with($arg1, '#') ? $arg1 : null;
            $action = IgnoreMemoAction::List;
            $nickToIgnore = null;
        } else {
            $arg1 = $context->args[1] ?? null;
            $isChannel = null !== $arg1 && str_starts_with($arg1, '#');
            $targetChannel = $isChannel ? $arg1 : null;
            $nickToIgnore = $isChannel ? ($context->args[2] ?? '') : ($arg1 ?? '');

            if ('' === $nickToIgnore) {
                $context->reply('error.syntax', ['syntax' => $context->trans('ignore.' . strtolower($sub) . '.syntax')]);

                return null;
            }

            $action = 'ADD' === $sub ? IgnoreMemoAction::Add : IgnoreMemoAction::Del;
        }

        $result = $this->ignoreMemoHandler->handle(new IgnoreMemo(
            senderNickId: $senderAccount->id,
            action: $action,
            channelName: $targetChannel,
            targetNick: $nickToIgnore,
        ));

        switch ($result->outcome) {
            case IgnoreMemoOutcome::ListNick:
            case IgnoreMemoOutcome::ListChannel:
                if ([] === $result->ignoredNicks) {
                    $context->reply('ignore.list_empty');

                    return null;
                }
                $context->reply('ignore.list_header');
                foreach ($result->ignoredNicks as $name) {
                    $context->replyRaw('  ' . $name);
                }
                $context->reply('ignore.list_footer');
                break;

            case IgnoreMemoOutcome::AddedNick:
            case IgnoreMemoOutcome::AddedChannel:
                $context->reply('ignore.added', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::DeletedNick:
            case IgnoreMemoOutcome::DeletedChannel:
                $context->reply('ignore.removed', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::AlreadyIgnored:
                $context->reply('ignore.already_ignored', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::NotIgnored:
                $context->reply('ignore.not_ignored', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::NickNotRegistered:
                $context->reply('ignore.nick_not_registered', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::ChannelNotRegistered:
                $context->reply('ignore.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case IgnoreMemoOutcome::AccessDenied:
                $context->reply('error.insufficient_access', [
                    'operation' => 'IGNORE',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;

            case IgnoreMemoOutcome::LimitReached:
                if (null !== $result->channelName) {
                    $context->reply('ignore.limit_reached_channel', [
                        'limit' => $this->ignoreListLimitChannel,
                        'channel' => $result->channelName,
                    ]);
                } else {
                    $context->reply('ignore.limit_reached_nick', ['limit' => $this->ignoreListLimitNick]);
                }
                break;
        }

        return null;
    }
}
