<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotd;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdHandlerInterface;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdOutcome;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdResult;
use App\OperServ\Application\UseCase\ManageMotd\MotdAction;
use App\OperServ\Application\UseCase\ManageMotd\MotdListEntry;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function sprintf;
use function strtoupper;
use function trim;

/** MOTD administration; IRC parsing and presentation only. */
final readonly class MotdCommand implements OperServCommandInterface
{
    public function __construct(private ManageMotdHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'MOTD';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('motd.syntax', [new Argument('action')], [
            'ADD' => new CommandArgumentDefinition('motd.add.syntax', [
                new Argument('target'),
                new Argument('delivery'),
                new Argument('expiry'),
                new Argument('message', rest: true),
            ]),
            'DEL' => new CommandArgumentDefinition('motd.del.syntax', [new Argument('id')]),
            'LIST' => new CommandArgumentDefinition('motd.list.syntax'),
            'CLEAN' => new CommandArgumentDefinition('motd.clean.syntax'),
        ]);
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'motd.help';
    }

    public function getExampleKey(): string
    {
        return 'motd.example';
    }

    public function getOrder(): int
    {
        return 39;
    }

    public function getShortDescKey(): string
    {
        return 'motd.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'motd.add.short', 'help_key' => 'motd.add.help', 'syntax_key' => 'motd.add.syntax', 'example_key' => 'motd.add.example'],
            ['name' => 'DEL', 'desc_key' => 'motd.del.short', 'help_key' => 'motd.del.help', 'syntax_key' => 'motd.del.syntax', 'example_key' => 'motd.del.example'],
            ['name' => 'LIST', 'desc_key' => 'motd.list.short', 'help_key' => 'motd.list.help', 'syntax_key' => 'motd.list.syntax', 'example_key' => 'motd.list.example'],
            ['name' => 'CLEAN', 'desc_key' => 'motd.clean.short', 'help_key' => 'motd.clean.help', 'syntax_key' => 'motd.clean.syntax', 'example_key' => 'motd.clean.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return 'operserv.motd';
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if ($validation->recognized && !$validation->isValid()) {
            $syntaxKey = $validation->syntaxKey ?? $this->getSyntaxKey();
            $hint = 'ADD' === strtoupper($context->args[0] ?? '')
                ? 'motd.add.syntax_hint'
                : 'motd.del.syntax_hint';
            $context->reply($hint, ['%syntax%' => $context->trans($syntaxKey)]);

            return;
        }

        $subcommand = strtoupper($context->args[0] ?? '');
        $action = match ($subcommand) {
            'ADD' => MotdAction::Add,
            'DEL' => MotdAction::Delete,
            'LIST' => MotdAction::List,
            'CLEAN' => MotdAction::Clean,
            default => MotdAction::Unknown,
        };
        $result = $this->handler->handle(new ManageMotd(
            $action,
            $context->sender->nick,
            $context->senderAccountId(),
            new DateTimeImmutable(),
            MotdAction::Add === $action ? trim($context->args[1] ?? '') : null,
            MotdAction::Add === $action ? match (strtoupper(trim($context->args[2] ?? ''))) {
                'NOTICE' => MessageDelivery::NonInteractive,
                'PRIVMSG' => MessageDelivery::Interactive,
                default => null,
            } : null,
            MotdAction::Add === $action ? trim($context->args[3] ?? '') : null,
            MotdAction::Add === $action ? trim(implode(' ', array_slice($context->args, 4))) : null,
            MotdAction::Delete === $action ? trim($context->args[1] ?? '') : null,
        ));

        $this->present($context, $action, $subcommand, $result);
    }

    private function present(OperServContext $context, MotdAction $action, string $subcommand, ManageMotdResult $result): void
    {
        match ($result->outcome) {
            ManageMotdOutcome::UnknownAction => $context->reply('motd.unknown_sub'),
            ManageMotdOutcome::InvalidAddRequest => $context->reply('motd.add.syntax_hint', ['%syntax%' => $context->trans('motd.add.syntax')]),
            ManageMotdOutcome::InvalidMessageType => $context->reply('motd.add.invalid_type'),
            ManageMotdOutcome::InvalidExpiry => $context->reply('motd.add.invalid_expiry'),
            ManageMotdOutcome::InvalidId, ManageMotdOutcome::NotFound => $context->reply('motd.del.not_found'),
            ManageMotdOutcome::ListEmpty => $context->reply('motd.list.empty'),
            ManageMotdOutcome::CleanEmpty => $context->reply('motd.clean.none'),
            ManageMotdOutcome::Added => $context->reply('motd.add.done', ['%id%' => (string) $result->id]),
            ManageMotdOutcome::Deleted => $context->reply('motd.del.done', ['%id%' => (string) $result->id]),
            ManageMotdOutcome::Listed => $this->presentList($context, $result),
            ManageMotdOutcome::Cleaned => $context->reply('motd.clean.done', ['%count%' => (string) $result->removedCount]),
        };
    }

    private function presentList(OperServContext $context, ManageMotdResult $result): void
    {
        $context->reply('motd.list.header');
        foreach ($result->entries as $entry) {
            $this->presentEntry($context, $entry);
        }
    }

    private function presentEntry(OperServContext $context, MotdListEntry $entry): void
    {
        $status = $entry->expired
            ? $context->trans('motd.list.expired')
            : ($entry->enabled ? $context->trans('motd.list.status_enabled') : $context->trans('motd.list.status_disabled'));
        $botNickname = '' !== $entry->botNickname ? $entry->botNickname : $context->trans('motd.list.no_bot');
        $expiresAt = null === $entry->expiresAt ? $context->trans('label.never') : $context->formatDate($entry->expiresAt);

        $context->replyRaw(sprintf(
            '#%d [%s] %s → %s | %s | %s | %s',
            $entry->id,
            match ($entry->delivery) {
                MessageDelivery::NonInteractive => 'NOTICE',
                MessageDelivery::Interactive => 'PRIVMSG',
            },
            $botNickname,
            $entry->text,
            $status,
            $expiresAt,
            $context->trans('motd.list.shown_count', ['%count%' => (string) $entry->shownCount]),
        ));
    }
}
