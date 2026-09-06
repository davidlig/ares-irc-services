<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickHistoryService;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Stringable;

use function array_slice;
use function assert;
use function count;
use function implode;
use function is_scalar;
use function sprintf;
use function strtoupper;
use function trim;

final class HistoryCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    private const string ACTION_ADD = 'ADD';

    private const string ACTION_DEL = 'DEL';

    private const string ACTION_VIEW = 'VIEW';

    private const string ACTION_CLEAR = 'CLEAR';

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickHistoryRepositoryInterface $historyRepository,
        private readonly NickHistoryService $historyService,
        private readonly int $historyViewLimit = 40,
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
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $action = strtoupper($context->args[1]);

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('history.not_registered', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        return match ($action) {
            self::ACTION_ADD => $this->handleAdd($context, $account->getId(), $targetNick),
            self::ACTION_DEL => $this->handleDel($context, $account->getId(), $targetNick),
            self::ACTION_VIEW => $this->handleView($context, $account->getId(), $targetNick),
            self::ACTION_CLEAR => $this->handleClear($context, $account->getId(), $targetNick),
            default => $this->rejectInvalidAction($context),
        };
    }

    private function rejectInvalidAction(NickServContext $context): CommandOutcome
    {
        $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

        return CommandOutcome::rejected();
    }

    private function handleAdd(NickServContext $context, int $nickId, string $targetNick): CommandOutcome
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

            return CommandOutcome::rejected();
        }

        $messageParts = array_slice($context->args, 2);
        $message = trim(implode(' ', $messageParts));

        if ('' === $message) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.add.syntax')]);

            return CommandOutcome::rejected();
        }

        assert(null !== $context->sender);
        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->historyService->recordAction(
            nickId: $nickId,
            action: 'HISTORY_ADD',
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
            message: $message,
        );

        $auditData = new IrcopAuditData(
            target: $targetNick,
            reason: $message,
        );

        $context->reply('history.add.success', ['%nickname%' => $targetNick]);

        return CommandOutcome::success($auditData);
    }

    private function handleDel(NickServContext $context, int $nickId, string $targetNick): CommandOutcome
    {
        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans('history.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $entryId = (int) $context->args[2];

        if ($entryId <= 0) {
            $context->reply('history.del.invalid_id', ['%id%' => $context->args[2]]);

            return CommandOutcome::rejected();
        }

        $history = $this->historyRepository->findById($entryId);

        if (null === $history || $history->getNickId() !== $nickId) {
            $context->reply('history.del.not_found', ['%id%' => $entryId]);

            return CommandOutcome::rejected();
        }

        $this->historyRepository->deleteById($entryId);

        $auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['entry_id' => $entryId],
        );

        $context->reply('history.del.success', ['%id%' => $entryId]);

        return CommandOutcome::success($auditData);
    }

    private function handleView(NickServContext $context, int $nickId, string $targetNick): CommandOutcome
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

        $total = $this->historyRepository->countByNickId($nickId);

        if (0 === $total) {
            $context->reply('history.view.no_entries', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        $limit = $showAll ? null : $this->historyViewLimit;
        $offset = $showAll ? 0 : ((int) $page - 1) * $this->historyViewLimit;

        $entries = $this->historyRepository->findByNickId($nickId, $limit, $offset);

        $totalPages = $showAll ? 1 : (int) ceil($total / $this->historyViewLimit);

        $start = $showAll ? 1 : ((int) $page - 1) * $this->historyViewLimit + 1;
        $end = $showAll ? $total : min((int) $page * $this->historyViewLimit, $total);

        $context->reply('history.view.header', [
            '%nickname%' => $targetNick,
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
                '%nickname%' => $targetNick,
                '%next_page%' => $page + 1,
            ]);
        }

        return CommandOutcome::rejected();
    }

    private function handleClear(NickServContext $context, int $nickId, string $targetNick): CommandOutcome
    {
        $count = $this->historyRepository->deleteByNickId($nickId);

        $auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['count' => $count],
        );

        $context->reply('history.clear.success', [
            '%count%' => $count,
            '%nickname%' => $targetNick,
        ]);

        return CommandOutcome::success($auditData);
    }

    private function formatOperator(?int $performedByNickId, string $performedBy, NickServContext $context): string
    {
        if (null !== $performedByNickId) {
            $operatorAccount = $this->nickRepository->findById($performedByNickId);

            if (null === $operatorAccount) {
                return sprintf('%s %s', $performedBy, $context->trans('history.unknown_operator'));
            }
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
