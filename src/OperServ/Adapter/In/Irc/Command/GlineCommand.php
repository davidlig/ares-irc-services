<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\UseCase\ManageGline\GlineAction;
use App\OperServ\Application\UseCase\ManageGline\GlineListEntry;
use App\OperServ\Application\UseCase\ManageGline\ManageGline;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineHandlerInterface;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineOutcome;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineResult;
use DateTimeImmutable;

use function array_slice;
use function count;
use function implode;
use function strtoupper;
use function trim;

/** GLINE ADD|DEL|LIST; IRC parsing and presentation only. */
final readonly class GlineCommand implements OperServCommandInterface
{
    public function __construct(private ManageGlineHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'GLINE';
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
        return 'gline.syntax';
    }

    public function getHelpKey(): string
    {
        return 'gline.help';
    }

    public function getOrder(): int
    {
        return 20;
    }

    public function getShortDescKey(): string
    {
        return 'gline.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'gline.add.short', 'help_key' => 'gline.add.help', 'syntax_key' => 'gline.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'gline.del.short', 'help_key' => 'gline.del.help', 'syntax_key' => 'gline.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'gline.list.short', 'help_key' => 'gline.list.help', 'syntax_key' => 'gline.list.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return 'operserv.gline';
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $subcommand = strtoupper($context->args[0] ?? '');
        $action = match ($subcommand) {
            'ADD' => GlineAction::Add,
            'DEL' => GlineAction::Delete,
            'LIST' => GlineAction::List,
            default => GlineAction::Unknown,
        };
        $result = $this->handler->handle(new ManageGline(
            $action,
            $context->sender->nick,
            $context->senderAccountId(),
            new DateTimeImmutable(),
            match ($action) {
                GlineAction::Add, GlineAction::Delete => trim($context->args[1] ?? ''),
                default => null,
            },
            GlineAction::Add === $action ? trim($context->args[2] ?? '') : null,
            GlineAction::Add === $action ? trim(implode(' ', array_slice($context->args, 3))) : null,
            GlineAction::List === $action ? trim($context->args[1] ?? '') : null,
        ));

        $this->present($context, $subcommand, $action, $result);
    }

    private function present(OperServContext $context, string $subcommand, GlineAction $action, ManageGlineResult $result): void
    {
        match ($result->outcome) {
            ManageGlineOutcome::UnknownAction => $context->reply('gline.unknown_sub', ['%sub%' => $subcommand]),
            ManageGlineOutcome::InvalidRequest => $context->reply('error.syntax', ['%syntax%' => $context->trans(GlineAction::Delete === $action ? 'gline.del.syntax' : 'gline.add.syntax')]),
            ManageGlineOutcome::InvalidMask => $context->reply('gline.invalid_mask'),
            ManageGlineOutcome::UserNotFound => $context->reply('gline.user_not_found', ['%nickname%' => (string) $result->mask]),
            ManageGlineOutcome::GlobalMask => $context->reply('gline.global_mask', ['%mask%' => (string) $result->mask]),
            ManageGlineOutcome::DangerousMask => $context->reply('gline.dangerous_mask', ['%mask%' => (string) $result->mask]),
            ManageGlineOutcome::ProtectedUser => $context->reply('gline.protected_user', ['%nickname%' => (string) $result->protectedNickname]),
            ManageGlineOutcome::InvalidExpiry => $context->reply('gline.invalid_expiry'),
            ManageGlineOutcome::AlreadyExists => $context->reply('gline.already_exists', ['%mask%' => (string) $result->mask]),
            ManageGlineOutcome::LimitReached => $context->reply('gline.max_entries', ['%max%' => (string) $result->limit]),
            ManageGlineOutcome::NotFound => $context->reply('gline.not_found', ['%mask%' => (string) $result->mask]),
            ManageGlineOutcome::ListEmpty => $context->reply('gline.list.empty'),
            ManageGlineOutcome::Added => $this->presentAdded($context, $result),
            ManageGlineOutcome::Deleted => $context->reply('gline.del.done', ['%mask%' => (string) $result->mask]),
            ManageGlineOutcome::Listed => $this->presentList($context, $result),
        };
    }

    private function presentAdded(OperServContext $context, ManageGlineResult $result): void
    {
        $duration = '0' === $result->expiry ? $context->trans('gline.permanent') : (string) $result->expiry;
        $context->reply('gline.add.done', [
            '%mask%' => (string) $result->mask,
            '%duration%' => $duration,
            '%reason%' => (string) $result->reason,
        ]);
    }

    private function presentList(OperServContext $context, ManageGlineResult $result): void
    {
        $context->reply('gline.list.header', ['%count%' => (string) count($result->entries)]);
        foreach ($result->entries as $index => $entry) {
            $this->presentEntry($context, $index, $entry);
        }
    }

    private function presentEntry(OperServContext $context, int $index, GlineListEntry $entry): void
    {
        $context->reply('gline.list.entry', [
            '%index%' => (string) ($index + 1),
            '%mask%' => "\x0304{$entry->mask}\x03",
            '%reason%' => $entry->reason ?? $context->trans('gline.list.no_reason'),
            '%nickname%' => $entry->creatorNickname ?? $context->trans('gline.list.unknown_creator'),
            '%expiration%' => null === $entry->expiresAt ? $context->trans('gline.list.never_expires') : $context->formatDate($entry->expiresAt),
        ]);
    }
}
