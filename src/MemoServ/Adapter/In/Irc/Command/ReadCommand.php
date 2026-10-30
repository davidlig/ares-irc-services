<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Read\ReadMemo;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Read\ReadMemoOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function str_starts_with;

/**
 * READ [#canal] <número>.
 * Without #canal = own nick inbox. With #canal = channel memos (requires MEMOREAD).
 */
final readonly class ReadCommand implements MemoServCommandInterface
{
    public function __construct(
        private ReadMemoHandlerInterface $readMemoHandler,
    ) {}

    public function getName(): string
    {
        return 'READ';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('read.syntax', [new Argument('channel', optional: true, prefix: '#'), new Argument('index', digits: true)]);
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
        return 'read.help';
    }

    public function getExampleKey(): string
    {
        return 'read.example';
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

        $result = $this->readMemoHandler->handle(new ReadMemo(
            senderNickId: $senderAccount->id,
            channelName: $targetChannel,
            index: $index,
            occurredAt: new DateTimeImmutable(),
        ));

        switch ($result->outcome) {
            case ReadMemoOutcome::Success:
                $context->replyComponent(Component::TableHeader, 'read.header', ['index' => $result->index, 'from' => $result->from ?? '']);
                $context->replyRaw(' ' . ($result->message ?? ''));
                $context->replyComponent(Component::Row, 'read.footer', ['date' => $context->renderComponent(Component::Separator, $context->formatDate($result->createdAt)), 'separator' => $context->separator()]);
                break;

            case ReadMemoOutcome::NotFound:
                $context->reply('read.not_found', ['index' => $result->index], Role::Error);
                break;

            case ReadMemoOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $targetChannel ?? '']);
                break;

            case ReadMemoOutcome::AccessDenied:
                $context->replyError('error.insufficient_access', [
                    'operation' => 'READ',
                    'channel' => $result->channelName ?? $targetChannel ?? '',
                ]);
                break;
        }

        return null;
    }
}
