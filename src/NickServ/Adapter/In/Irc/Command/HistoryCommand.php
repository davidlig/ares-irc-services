<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\History\HistoryNick;
use App\NickServ\Application\UseCase\History\HistoryNickAction;
use App\NickServ\Application\UseCase\History\HistoryNickHandlerInterface;
use App\NickServ\Application\UseCase\History\HistoryNickOutcome;
use App\NickServ\Application\UseCase\History\HistoryNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Stringable;

use function array_slice;
use function base64_decode;
use function count;
use function implode;
use function inet_ntop;
use function is_scalar;
use function sprintf;
use function str_starts_with;
use function strtoupper;
use function trim;

final readonly class HistoryCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private HistoryNickHandlerInterface $handler,
        private Clock $clock,
    ) {}

    public function getName(): string
    {
        return 'HISTORY';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('history.syntax', [
            new Argument('nickname'),
            new Argument('action'),
        ], [
            'ADD' => new CommandArgumentDefinition('history.add.syntax', [new Argument('message', rest: true)]),
            'DEL' => new CommandArgumentDefinition('history.del.syntax', [new Argument('id')]),
            'VIEW' => new CommandArgumentDefinition('history.view.syntax', [new Argument('page', optional: true)]),
            'CLEAR' => new CommandArgumentDefinition('history.clear.syntax'),
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
        return 'history.help';
    }

    public function getExampleKey(): string
    {
        return 'history.example';
    }

    public function getOrder(): int
    {
        return 200;
    }

    public function getShortDescKey(): string
    {
        return 'history.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'history.add.short', 'help_key' => 'history.add.help', 'syntax_key' => 'history.add.syntax', 'example_key' => 'history.add.example'],
            ['name' => 'DEL', 'desc_key' => 'history.del.short', 'help_key' => 'history.del.help', 'syntax_key' => 'history.del.syntax', 'example_key' => 'history.del.example'],
            ['name' => 'VIEW', 'desc_key' => 'history.view.short', 'help_key' => 'history.view.help', 'syntax_key' => 'history.view.syntax', 'example_key' => 'history.view.example'],
            ['name' => 'CLEAR', 'desc_key' => 'history.clear.short', 'help_key' => 'history.clear.help', 'syntax_key' => 'history.clear.syntax', 'example_key' => 'history.clear.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::HISTORY;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->replySyntax($validation->syntaxKey);

            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $action = HistoryNickAction::tryFrom(strtoupper($context->args[1]));

        if (!$validation->recognized || null === $action) {
            $context->replySyntax($this->getSyntaxKey());

            return CommandOutcome::rejected();
        }

        $message = null;
        $entryId = null;
        $page = 1;
        $showAll = false;

        switch ($action) {
            case HistoryNickAction::Add:
                $message = trim(implode(' ', array_slice($context->args, 2)));
                if ('' === $message) {
                    $context->replySyntax('history.add.syntax');

                    return CommandOutcome::rejected();
                }
                break;

            case HistoryNickAction::Del:
                $rawId = $context->args[2];
                $entryId = (int) $rawId;
                if ($entryId <= 0) {
                    $context->reply('history.del.invalid_id', ['%id%' => new Fragment($rawId, Component::Emphasis)], Role::Error);

                    return CommandOutcome::rejected();
                }
                break;

            case HistoryNickAction::View:
                if (count($context->args) >= 3) {
                    $pageArg = strtoupper($context->args[2]);
                    if ('ALL' === $pageArg) {
                        $showAll = true;
                    } else {
                        $page = (int) $context->args[2];
                        if ($page < 1) {
                            $page = 1;
                        }
                    }
                }
                break;

            case HistoryNickAction::Clear:
                break;
        }

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);

        $result = $this->handler->handle(new HistoryNick(
            nickname: $targetNick,
            action: $action,
            occurredAt: $this->clock->now(),
            message: $message,
            entryId: $entryId,
            page: $page,
            showAll: $showAll,
            operatorNick: $sender->nick,
            operatorNickId: $context->senderAccount?->getId(),
            operatorIp: $ip,
            operatorHost: $host,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, HistoryNickResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case HistoryNickOutcome::NotRegistered:
                $context->replyMissingNick('error.nick_not_registered', $result->targetNick ?? '');

                return CommandOutcome::rejected();

            case HistoryNickOutcome::AddSuccess:
                $context->replyEmphasized('history.add.success', ['%nickname%' => $result->targetNick ?? ''], Role::Success, ['nickname']);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    reason: $result->message ?? '',
                ));

            case HistoryNickOutcome::DelInvalidId:
                $context->reply('history.del.invalid_id', ['%id%' => new Fragment($result->rawId ?? '', Component::Emphasis)], Role::Error);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::DelNotFound:
                $context->reply('history.del.not_found', ['%id%' => new Fragment((string) ($result->entryId ?? 0), Component::Emphasis)], Role::Error);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::DelSuccess:
                $context->reply('history.del.success', ['%id%' => new Fragment((string) ($result->entryId ?? 0), Component::Emphasis)], Role::Success);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    extra: ['entry_id' => $result->entryId],
                ));

            case HistoryNickOutcome::ClearSuccess:
                $context->replyEmphasized('history.clear.success', [
                    '%count%' => $result->deletedCount,
                    '%nickname%' => $result->targetNick ?? '',
                ], Role::Success, ['count', 'nickname']);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    extra: ['count' => $result->deletedCount],
                ));

            case HistoryNickOutcome::ViewNoEntries:
                $context->replyEmphasized('history.view.no_entries', ['%nickname%' => $result->targetNick ?? ''], Role::Information, ['nickname']);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::ViewSuccess:
                $this->presentView($context, $result);

                return CommandOutcome::rejected();
        }
    }

    private function presentView(NickServContext $context, HistoryNickResult $result): void
    {
        $context->replyComponentWithLocalizedFragments(Component::Text, 'history.view.header', [
            '%nickname%' => new Fragment($result->targetNick ?? '', Component::Status),
            '%start%' => $result->start,
            '%end%' => $result->end,
            '%total%' => $result->total,
        ], ['heading' => ['history.view.header_title', Component::TableHeader]]);

        foreach ($result->entries as $entry) {
            $operator = $this->formatOperator($entry->performedByNickId, $entry->performedBy, $entry->operatorExists, $context);
            $message = $this->translateMessage($entry->message, $entry->extraData, $context);

            $context->replyComponent(Component::Text, 'history.view.entry', [
                '%id%' => $entry->id,
                '%date%' => $context->formatDate($entry->performedAt),
                '%action%' => new Fragment($entry->action, Component::Positive),
                '%operator%' => $operator,
                '%message%' => $message,
            ]);

            if (!empty($entry->extraData)) {
                $formattedExtra = $this->formatExtraData($entry->extraData, $context);
                if ('' !== $formattedExtra) {
                    $context->replyComponent(Component::IndentedRow, 'history.view.extra', ['%extra%' => new Fragment($formattedExtra, Component::DetailValue)]);
                }
            }
        }

        if (!$result->showAll && $result->page < $result->totalPages) {
            $context->replyComponentWithLocalizedFragments(Component::Text, 'history.view.page_hint', [
                '%nickname%' => $result->targetNick ?? '',
                '%next_page%' => $result->page + 1,
            ], ['command' => ['history.view.page_hint_command', Component::Command]]);
        }
    }

    private function formatOperator(?int $performedByNickId, string $performedBy, bool $operatorExists, NickServContext $context): string
    {
        if (null !== $performedByNickId && !$operatorExists) {
            return sprintf('%s %s', $performedBy, $context->trans('history.unknown_operator'));
        }

        return $performedBy;
    }

    /**
     * @param array<string, mixed> $extraData
     */
    private function translateMessage(string $message, array $extraData, NickServContext $context): string
    {
        if (!str_starts_with($message, 'history.message.')) {
            return $message;
        }

        $params = [];
        if (isset($extraData['old_email'])) {
            $params['%old_email%'] = $this->stringifyExtra($extraData['old_email']);
        }
        if (isset($extraData['new_email'])) {
            $params['%new_email%'] = $this->stringifyExtra($extraData['new_email']);
        }

        return $context->trans($message, $params);
    }

    /**
     * @param array<string, mixed> $extraData
     */
    private function formatExtraData(array $extraData, NickServContext $context): string
    {
        $parts = [];

        if (isset($extraData['duration'])) {
            $parts[] = $context->trans('history.extra.duration', ['%value%' => $this->stringifyExtra($extraData['duration'])]);
        }
        if (isset($extraData['expires_at'])) {
            $parts[] = $context->trans('history.extra.expires_at', ['%value%' => $this->stringifyExtra($extraData['expires_at'])]);
        }
        if (isset($extraData['old_email'])) {
            $parts[] = $context->trans('history.extra.old_email', ['%value%' => $this->stringifyExtra($extraData['old_email'])]);
        }
        if (isset($extraData['new_email'])) {
            $parts[] = $context->trans('history.extra.new_email', ['%value%' => $this->stringifyExtra($extraData['new_email'])]);
        }
        if (isset($extraData['method'])) {
            $parts[] = $context->trans('history.extra.method', ['%value%' => $this->stringifyExtra($extraData['method'])]);
        }
        if (isset($extraData['ip'])) {
            $parts[] = $context->trans('history.extra.ip', ['%value%' => $this->stringifyExtra($extraData['ip'])]);
        }
        if (isset($extraData['host'])) {
            $parts[] = $context->trans('history.extra.host', ['%value%' => $this->stringifyExtra($extraData['host'])]);
        }

        return implode(', ', $parts);
    }

    private function stringifyExtra(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);
        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
