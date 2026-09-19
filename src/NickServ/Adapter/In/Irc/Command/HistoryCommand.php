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

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'history.syntax';
    }

    public function getHelpKey(): string
    {
        return 'history.help';
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
            ['name' => 'ADD', 'desc_key' => 'history.add.short', 'help_key' => 'history.add.help', 'syntax_key' => 'history.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'history.del.short', 'help_key' => 'history.del.help', 'syntax_key' => 'history.del.syntax'],
            ['name' => 'VIEW', 'desc_key' => 'history.view.short', 'help_key' => 'history.view.help', 'syntax_key' => 'history.view.syntax'],
            ['name' => 'CLEAR', 'desc_key' => 'history.clear.short', 'help_key' => 'history.clear.help', 'syntax_key' => 'history.clear.syntax'],
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

        $targetNick = $context->args[0];
        $action = HistoryNickAction::tryFrom(strtoupper($context->args[1]));

        if (null === $action) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $message = null;
        $entryId = null;
        $page = 1;
        $showAll = false;

        switch ($action) {
            case HistoryNickAction::Add:
                if (count($context->args) < 3) {
                    $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

                    return CommandOutcome::rejected();
                }

                $message = trim(implode(' ', array_slice($context->args, 2)));
                if ('' === $message) {
                    $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

                    return CommandOutcome::rejected();
                }
                break;

            case HistoryNickAction::Del:
                if (count($context->args) < 3) {
                    $context->reply('error.syntax', ['syntax' => $context->trans('history.del.syntax')]);

                    return CommandOutcome::rejected();
                }

                $rawId = $context->args[2];
                $entryId = (int) $rawId;
                if ($entryId <= 0) {
                    $context->reply('history.del.invalid_id', ['%id%' => $rawId]);

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
                $context->reply('history.not_registered', ['%nickname%' => $result->targetNick ?? '']);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::AddSuccess:
                $context->reply('history.add.success', ['%nickname%' => $result->targetNick ?? '']);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    reason: $result->message ?? '',
                ));

            case HistoryNickOutcome::DelInvalidId:
                $context->reply('history.del.invalid_id', ['%id%' => $result->rawId ?? '']);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::DelNotFound:
                $context->reply('history.del.not_found', ['%id%' => $result->entryId ?? 0]);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::DelSuccess:
                $context->reply('history.del.success', ['%id%' => $result->entryId ?? 0]);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    extra: ['entry_id' => $result->entryId],
                ));

            case HistoryNickOutcome::ClearSuccess:
                $context->reply('history.clear.success', [
                    '%count%' => $result->deletedCount,
                    '%nickname%' => $result->targetNick ?? '',
                ]);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    extra: ['count' => $result->deletedCount],
                ));

            case HistoryNickOutcome::ViewNoEntries:
                $context->reply('history.view.no_entries', ['%nickname%' => $result->targetNick ?? '']);

                return CommandOutcome::rejected();

            case HistoryNickOutcome::ViewSuccess:
                $this->presentView($context, $result);

                return CommandOutcome::rejected();
        }
    }

    private function presentView(NickServContext $context, HistoryNickResult $result): void
    {
        $context->reply('history.view.header', [
            '%nickname%' => $result->targetNick ?? '',
            '%start%' => $result->start,
            '%end%' => $result->end,
            '%total%' => $result->total,
        ]);

        foreach ($result->entries as $entry) {
            $operator = $this->formatOperator($entry->performedByNickId, $entry->performedBy, $entry->operatorExists, $context);
            $message = $this->translateMessage($entry->message, $entry->extraData, $context);

            $context->reply('history.view.entry', [
                '%id%' => $entry->id,
                '%date%' => $context->formatDate($entry->performedAt),
                '%action%' => $entry->action,
                '%operator%' => $operator,
                '%message%' => $message,
            ]);

            if (!empty($entry->extraData)) {
                $formattedExtra = $this->formatExtraData($entry->extraData, $context);
                if ('' !== $formattedExtra) {
                    $context->reply('history.view.extra', ['%extra%' => $formattedExtra]);
                }
            }
        }

        if (!$result->showAll && $result->page < $result->totalPages) {
            $context->reply('history.view.page_hint', [
                '%nickname%' => $result->targetNick ?? '',
                '%next_page%' => $result->page + 1,
            ]);
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
