<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ManageHistory\ChannelHistoryAction;
use App\ChanServ\Application\UseCase\ManageHistory\ChannelHistoryEntryView;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistory;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistoryHandlerInterface;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistoryOutcome;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistoryResult;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use DateTimeImmutable;

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

final readonly class HistoryCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private ManageChannelHistoryHandlerInterface $handler) {}

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
        return ChanServPermission::HISTORY;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }

        $action = $this->parseAction($context);
        if (null === $action) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $message = null;
        $entryId = null;
        $page = 1;
        $showAll = false;
        if (ChannelHistoryAction::Add === $action) {
            $message = trim(implode(' ', array_slice($context->args, 2)));
            if (count($context->args) < 3 || '' === $message) {
                $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

                return CommandOutcome::rejected();
            }
        } elseif (ChannelHistoryAction::Delete === $action) {
            if (count($context->args) < 3) {
                $context->reply('error.syntax', ['syntax' => $context->trans('history.del.syntax')]);

                return CommandOutcome::rejected();
            }

            $entryId = (int) $context->args[2];
            if ($entryId <= 0) {
                $context->reply('history.del.invalid_id', ['%id%' => $context->args[2]]);

                return CommandOutcome::rejected();
            }
        } elseif (ChannelHistoryAction::View === $action && count($context->args) >= 3) {
            if ('ALL' === strtoupper($context->args[2])) {
                $showAll = true;
            } else {
                $page = (int) $context->args[2];
            }
        }

        $result = $this->handler->handle(new ManageChannelHistory(
            channelName: $channelName,
            action: $action,
            actorNickname: $sender->nick,
            actorAccountId: $context->senderAccount?->id,
            actorIp: $this->decodeIp($sender->ipBase64),
            actorHost: sprintf('%s@%s', $sender->ident, $sender->hostname),
            occurredAt: new DateTimeImmutable(),
            message: $message,
            entryId: $entryId,
            page: $page,
            showAll: $showAll,
        ));

        return $this->present($context, $channelName, $message, $showAll, $result);
    }

    private function parseAction(ChanServContext $context): ?ChannelHistoryAction
    {
        return match (strtoupper($context->args[1] ?? '')) {
            'ADD' => ChannelHistoryAction::Add,
            'DEL' => ChannelHistoryAction::Delete,
            'VIEW' => ChannelHistoryAction::View,
            'CLEAR' => ChannelHistoryAction::Clear,
            default => null,
        };
    }

    private function present(ChanServContext $context, string $channelName, ?string $message, bool $showAll, ManageChannelHistoryResult $result): CommandOutcome
    {
        if (ManageChannelHistoryOutcome::ChannelNotRegistered === $result->outcome) {
            $context->reply('history.not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        if (ManageChannelHistoryOutcome::EntryNotFound === $result->outcome) {
            $context->reply('history.del.not_found', ['%id%' => $result->affectedEntryId]);

            return CommandOutcome::rejected();
        }

        if (ManageChannelHistoryOutcome::NoEntries === $result->outcome) {
            $context->reply('history.view.no_entries', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        if (ManageChannelHistoryOutcome::Added === $result->outcome) {
            $context->reply('history.add.success', ['%channel%' => $channelName]);

            return CommandOutcome::success(new IrcopAuditData(target: $channelName, reason: $message));
        }

        if (ManageChannelHistoryOutcome::Deleted === $result->outcome) {
            $context->reply('history.del.success', ['%id%' => $result->affectedEntryId]);

            return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['entry_id' => $result->affectedEntryId]));
        }

        if (ManageChannelHistoryOutcome::Cleared === $result->outcome) {
            $context->reply('history.clear.success', ['%count%' => $result->total, '%channel%' => $channelName]);

            return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['count' => $result->total]));
        }

        $this->presentEntries($context, $channelName, $showAll, $result);

        return CommandOutcome::rejected();
    }

    private function presentEntries(ChanServContext $context, string $channelName, bool $showAll, ManageChannelHistoryResult $result): void
    {
        $context->reply('history.view.header', [
            '%channel%' => $channelName,
            '%start%' => $result->start,
            '%end%' => $result->end,
            '%total%' => $result->total,
        ]);

        foreach ($result->entries as $entry) {
            $this->presentEntry($context, $entry);
        }

        if (!$showAll && $result->page < $result->totalPages) {
            $context->reply('history.view.page_hint', [
                '%channel%' => $channelName,
                '%next_page%' => $result->page + 1,
            ]);
        }
    }

    private function presentEntry(ChanServContext $context, ChannelHistoryEntryView $entry): void
    {
        $operator = $entry->operatorAccountMissing
            ? sprintf('%s %s', $entry->performedBy, $context->trans('history.unknown_operator'))
            : $entry->performedBy;
        $context->reply('history.view.entry', [
            '%id%' => $entry->id,
            '%date%' => $context->formatDate($entry->performedAt),
            '%action%' => $entry->action,
            '%operator%' => $operator,
            '%message%' => $this->translateMessage($entry->message, $entry->extraData, $context),
        ]);

        $formattedExtra = $this->formatExtraData($entry->extraData, $context);
        if ([] !== $entry->extraData && '' !== $formattedExtra) {
            $context->reply('history.view.extra', ['%extra%' => $formattedExtra]);
        }
    }

    /** @param array<string, mixed> $extraData */
    private function translateMessage(string $message, array $extraData, ChanServContext $context): string
    {
        if (!str_starts_with($message, 'history.message.')) {
            return $message;
        }

        $params = [];
        foreach (['old_founder', 'new_founder', 'old_successor', 'new_successor', 'target_nickname', 'level', 'mask'] as $key) {
            if (isset($extraData[$key])) {
                $params['%' . $key . '%'] = $this->stringifyExtra($extraData[$key]);
            }
        }

        return $context->trans($message, $params);
    }

    /** @param array<string, mixed> $extraData */
    private function formatExtraData(array $extraData, ChanServContext $context): string
    {
        $parts = [];
        foreach (['duration', 'expires_at', 'old_founder', 'new_founder', 'old_successor', 'new_successor', 'target_nickname', 'level', 'mask', 'ip', 'host'] as $key) {
            if (isset($extraData[$key])) {
                $parts[] = $context->trans('history.extra.' . $key, ['%value%' => $this->stringifyExtra($extraData[$key])]);
            }
        }

        return implode(', ', $parts);
    }

    private function stringifyExtra(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
