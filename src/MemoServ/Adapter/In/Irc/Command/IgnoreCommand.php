<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemo;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoAction;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function str_starts_with;
use function strtoupper;

/**
 * IGNORE {ADD|DEL|LIST} [#canal] [nick].
 * For nick: IGNORE ADD nick, IGNORE DEL nick, IGNORE LIST.
 * For channel: IGNORE ADD #chan nick, IGNORE DEL #chan nick, IGNORE LIST #chan (requires MEMOCHANGE for ADD/DEL).
 */
final readonly class IgnoreCommand implements MemoServCommandInterface
{
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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('ignore.syntax', [new Argument('action')], variants: [
            'ADD' => new CommandArgumentDefinition('ignore.add.syntax', [new Argument('channel', optional: true, prefix: '#'), new Argument('nickname')]),
            'DEL' => new CommandArgumentDefinition('ignore.del.syntax', [new Argument('channel', optional: true, prefix: '#'), new Argument('nickname')]),
            'LIST' => new CommandArgumentDefinition('ignore.list.syntax', [new Argument('channel', optional: true, prefix: '#')]),
        ]);
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
        return 'ignore.help';
    }

    public function getExampleKey(): string
    {
        return 'ignore.example';
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
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => $this->getArgumentDefinition()->variants['ADD']->syntaxKey, 'example_key' => 'ignore.add.example'],
            ['name' => 'DEL', 'desc_key' => 'ignore.del.short', 'help_key' => 'ignore.del.help', 'syntax_key' => $this->getArgumentDefinition()->variants['DEL']->syntaxKey, 'example_key' => 'ignore.del.example'],
            ['name' => 'LIST', 'desc_key' => 'ignore.list.short', 'help_key' => 'ignore.list.help', 'syntax_key' => $this->getArgumentDefinition()->variants['LIST']->syntaxKey, 'example_key' => 'ignore.list.example'],
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
            $context->replyError('error.not_identified');

            return null;
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (!$validation->isValid()) {
            $context->replyError('error.syntax', ['syntax' => $context->trans($validation->syntaxKey ?? $this->getSyntaxKey())]);

            return null;
        }

        $sub = strtoupper($context->args[0] ?? '');
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
                    $context->reply('ignore.list_empty', [], Role::Information);

                    return null;
                }
                $context->replyComponent(Component::TableHeader, 'ignore.list_header');
                foreach ($result->ignoredNicks as $name) {
                    $context->replyRaw('  ' . $name);
                }
                $context->replySeparator();
                break;

            case IgnoreMemoOutcome::AddedNick:
            case IgnoreMemoOutcome::AddedChannel:
                $context->reply('ignore.added', ['nickname' => $context->emphasize($result->targetNick ?? ''), 'nick' => $context->emphasize($result->targetNick ?? '')], Role::Success);
                break;

            case IgnoreMemoOutcome::DeletedNick:
            case IgnoreMemoOutcome::DeletedChannel:
                $context->reply('ignore.removed', ['nickname' => $context->emphasize($result->targetNick ?? ''), 'nick' => $context->emphasize($result->targetNick ?? '')], Role::Success);
                break;

            case IgnoreMemoOutcome::AlreadyIgnored:
                $context->reply('ignore.already_ignored', ['nickname' => $context->emphasize($result->targetNick ?? ''), 'nick' => $context->emphasize($result->targetNick ?? '')], Role::Error);
                break;

            case IgnoreMemoOutcome::NotIgnored:
                $context->reply('ignore.not_ignored', ['nickname' => $context->emphasize($result->targetNick ?? ''), 'nick' => $context->emphasize($result->targetNick ?? '')], Role::Error);
                break;

            case IgnoreMemoOutcome::NickNotRegistered:
                $context->replyError('error.nick_not_registered', ['nickname' => $result->targetNick ?? '', 'nick' => $result->targetNick ?? '']);
                break;

            case IgnoreMemoOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $result->channelName ?? '']);
                break;

            case IgnoreMemoOutcome::AccessDenied:
                $context->replyError('error.insufficient_access', [
                    'operation' => 'IGNORE',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;

            case IgnoreMemoOutcome::LimitReached:
                if (null !== $result->channelName) {
                    $context->reply('ignore.limit_reached_channel', [
                        'limit' => $context->emphasize((string) $this->ignoreListLimitChannel),
                        'channel' => $context->emphasize($result->channelName),
                    ], Role::Error);
                } else {
                    $context->reply('ignore.limit_reached_nick', ['limit' => $context->emphasize((string) $this->ignoreListLimitNick)], Role::Error);
                }
                break;
        }

        return null;
    }
}
