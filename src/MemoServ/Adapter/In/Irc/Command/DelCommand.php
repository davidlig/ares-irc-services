<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Del\DelMemo;
use App\MemoServ\Application\UseCase\Del\DelMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Del\DelMemoOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function str_starts_with;

/**
 * DEL [#canal] <número>.
 * Delete a memo by its 1-based index. For channel, requires MEMOCHANGE.
 */
final readonly class DelCommand implements MemoServCommandInterface
{
    public function __construct(
        private DelMemoHandlerInterface $delMemoHandler,
    ) {}

    public function getName(): string
    {
        return 'DEL';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('del.syntax', [new Argument('channel', optional: true, prefix: '#'), new Argument('index', digits: true)]);
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
        return 'del.help';
    }

    public function getExampleKey(): string
    {
        return 'del.example';
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

        $first = $context->args[0] ?? '';
        $isChannel = str_starts_with($first, '#');
        $targetChannel = $isChannel ? $first : null;
        $indexArg = $isChannel ? ($context->args[1] ?? '') : $first;

        $index = (int) $indexArg;

        $result = $this->delMemoHandler->handle(new DelMemo(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
            index: $index,
        ));

        switch ($result->outcome) {
            case DelMemoOutcome::Deleted:
                $context->reply('del.deleted', ['index' => $result->index], Role::Success);
                break;

            case DelMemoOutcome::NotFound:
                $context->reply('del.not_found', ['index' => $result->index], Role::Error);
                break;

            case DelMemoOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $targetChannel ?? '']);
                break;

            case DelMemoOutcome::AccessDenied:
                $context->replyError('error.insufficient_access', [
                    'operation' => 'DEL',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;
        }

        return null;
    }
}
