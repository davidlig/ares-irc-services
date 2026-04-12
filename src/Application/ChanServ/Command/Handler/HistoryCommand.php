<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelHistoryService;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function array_slice;
use function count;
use function implode;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

final class HistoryCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private const string ACTION_ADD = 'ADD';

    private const string ACTION_DEL = 'DEL';

    private const string ACTION_VIEW = 'VIEW';

    private const string ACTION_CLEAR = 'CLEAR';

    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChannelHistoryRepositoryInterface $historyRepository,
        private readonly ChannelHistoryService $historyService,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly int $historyViewLimit = 40,
    ) {
    }

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

    public function getRequiredPermission(): ?string
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

    public function execute(ChanServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $action = strtoupper($context->args[1] ?? '');

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));

        if (null === $channel) {
            $context->reply('history.not_registered', ['%channel%' => $channelName]);

            return;
        }

        match ($action) {
            self::ACTION_ADD => $this->handleAdd($context, $channel->getId(), $channelName),
            self::ACTION_DEL => $this->handleDel($context, $channel->getId(), $channelName),
            self::ACTION_VIEW => $this->handleView($context, $channel->getId(), $channelName),
            self::ACTION_CLEAR => $this->handleClear($context, $channel->getId(), $channelName),
            default => $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]),
        };
    }

    private function handleAdd(ChanServContext $context, int $channelId, string $channelName): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

            return;
        }

        $messageParts = array_slice($context->args, 2);
        $message = trim(implode(' ', $messageParts));

        if ('' === $message) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

            return;
        }

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->historyService->recordAction(
            channelId: $channelId,
            action: 'HISTORY_ADD',
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
            message: $message,
        );

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            reason: $message,
        );

        $context->reply('history.add.success', ['%channel%' => $channelName]);
    }

    private function handleDel(ChanServContext $context, int $channelId, string $channelName): void
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.del.syntax')]);

            return;
        }

        $entryId = (int) $context->args[2];

        if ($entryId <= 0) {
            $context->reply('history.del.invalid_id', ['%id%' => $context->args[2]]);

            return;
        }

        $history = $this->historyRepository->findById($entryId);

        if (null === $history || $history->getChannelId() !== $channelId) {
            $context->reply('history.del.not_found', ['%id%' => $entryId]);

            return;
        }

        $this->historyRepository->deleteById($entryId);

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            extra: ['entry_id' => $entryId],
        );

        $context->reply('history.del.success', ['%id%' => $entryId]);
    }

    private function handleView(ChanServContext $context, int $channelId, string $channelName): void
    {
        $page = 1;
        $showAll = false;

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

        $total = $this->historyRepository->countByChannelId($channelId);

        if (0 === $total) {
            $context->reply('history.view.no_entries', ['%channel%' => $channelName]);

            return;
        }

        $limit = $showAll ? null : $this->historyViewLimit;
        $offset = $showAll ? 0 : ((int) $page - 1) * $this->historyViewLimit;

        $entries = $this->historyRepository->findByChannelId($channelId, $limit, $offset);

        $totalPages = $showAll ? 1 : (int) ceil($total / $this->historyViewLimit);

        $start = $showAll ? 1 : ((int) $page - 1) * $this->historyViewLimit + 1;
        $end = $showAll ? $total : min((int) $page * $this->historyViewLimit, $total);

        $context->reply('history.view.header', [
            '%channel%' => $channelName,
            '%start%' => $start,
            '%end%' => $end,
            '%total%' => $total,
        ]);

        foreach ($entries as $entry) {
            $operator = $this->formatOperator($entry->getPerformedByNickId(), $entry->getPerformedBy(), $context);

            $message = $this->translateMessage($entry->getMessage(), $entry->getExtraData(), $context);

            $context->reply('history.view.entry', [
                '%id%' => $entry->getId(),
                '%date%' => $context->formatDate($entry->getPerformedAt()),
                '%action%' => $entry->getAction(),
                '%operator%' => $operator,
                '%message%' => $message,
            ]);

            $extraData = $entry->getExtraData();

            if (!empty($extraData)) {
                $formattedExtra = $this->formatExtraData($extraData, $context);

                if ('' !== $formattedExtra) {
                    $context->reply('history.view.extra', ['%extra%' => $formattedExtra]);
                }
            }
        }

        if (!$showAll && $page < $totalPages) {
            $context->reply('history.view.page_hint', [
                '%channel%' => $channelName,
                '%next_page%' => $page + 1,
            ]);
        }
    }

    private function handleClear(ChanServContext $context, int $channelId, string $channelName): void
    {
        $count = $this->historyRepository->deleteByChannelId($channelId);

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            extra: ['count' => $count],
        );

        $context->reply('history.clear.success', [
            '%count%' => $count,
            '%channel%' => $channelName,
        ]);
    }

    private function formatOperator(?int $performedByNickId, string $performedBy, ChanServContext $context): string
    {
        if (null !== $performedByNickId) {
            $operatorAccount = $this->nickRepository->findById($performedByNickId);

            if (null === $operatorAccount) {
                return sprintf('%s %s', $performedBy, $context->trans('history.unknown_operator'));
            }
        }

        return $performedBy;
    }

    private function translateMessage(string $message, array $extraData, ChanServContext $context): string
    {
        if (!str_starts_with($message, 'history.message.')) {
            return $message;
        }

        $params = [];

        if (isset($extraData['old_founder'])) {
            $params['%old_founder%'] = $extraData['old_founder'] ?? '(none)';
        }

        if (isset($extraData['new_founder'])) {
            $params['%new_founder%'] = $extraData['new_founder'];
        }

        if (isset($extraData['old_successor'])) {
            $params['%old_successor%'] = $extraData['old_successor'] ?? '(none)';
        }

        if (isset($extraData['new_successor'])) {
            $params['%new_successor%'] = $extraData['new_successor'] ?? '(none)';
        }

        if (isset($extraData['target_nickname'])) {
            $params['%target_nickname%'] = $extraData['target_nickname'];
        }

        if (isset($extraData['level'])) {
            $params['%level%'] = $extraData['level'];
        }

        if (isset($extraData['mask'])) {
            $params['%mask%'] = $extraData['mask'];
        }

        return $context->trans($message, $params);
    }

    private function formatExtraData(array $extraData, ChanServContext $context): string
    {
        $parts = [];

        if (isset($extraData['duration'])) {
            $parts[] = $context->trans('history.extra.duration', ['%value%' => $extraData['duration']]);
        }

        if (isset($extraData['expires_at'])) {
            $parts[] = $context->trans('history.extra.expires_at', ['%value%' => $extraData['expires_at']]);
        }

        if (isset($extraData['old_founder'])) {
            $parts[] = $context->trans('history.extra.old_founder', ['%value%' => $extraData['old_founder'] ?? '(none)']);
        }

        if (isset($extraData['new_founder'])) {
            $parts[] = $context->trans('history.extra.new_founder', ['%value%' => $extraData['new_founder']]);
        }

        if (isset($extraData['old_successor'])) {
            $parts[] = $context->trans('history.extra.old_successor', ['%value%' => $extraData['old_successor'] ?? '(none)']);
        }

        if (isset($extraData['new_successor'])) {
            $parts[] = $context->trans('history.extra.new_successor', ['%value%' => $extraData['new_successor'] ?? '(none)']);
        }

        if (isset($extraData['target_nickname'])) {
            $parts[] = $context->trans('history.extra.target_nickname', ['%value%' => $extraData['target_nickname']]);
        }

        if (isset($extraData['level'])) {
            $parts[] = $context->trans('history.extra.level', ['%value%' => $extraData['level']]);
        }

        if (isset($extraData['mask'])) {
            $parts[] = $context->trans('history.extra.mask', ['%value%' => $extraData['mask']]);
        }

        if (isset($extraData['ip'])) {
            $parts[] = $context->trans('history.extra.ip', ['%value%' => $extraData['ip']]);
        }

        if (isset($extraData['host'])) {
            $parts[] = $context->trans('history.extra.host', ['%value%' => $extraData['host']]);
        }

        return implode(', ', $parts);
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

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
